<?php

declare(strict_types=1);

namespace App\Admin;

use App\Catalog\ProductSync;
use App\Orders\SupplierOrderService;
use App\Supplier\Wtb\WtbClient;
use App\Support\Clock;
use App\Support\Db;
use App\Support\SystemFlags;
use App\Support\Translator;

/**
 * Avvisi della dashboard admin (docs/07): restano finché la causa non sparisce.
 * Ogni modulo aggiunge qui i suoi controlli.
 */
final class Alerts
{
    public function __construct(
        private readonly Db $db,
        private readonly SystemFlags $flags,
        private readonly Translator $t,
        private readonly SupplierOrderService $supplierOrders,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<array{level: string, title: string, text: string, href: string}> */
    public function all(): array
    {
        $alerts = [];
        $auth = $this->flags->getJson(WtbClient::FLAG_AUTH_ERROR);
        if ($auth !== null) {
            $alerts[] = [
                'level' => 'error',
                'title' => $this->t->get('admin.alerts.auth_title'),
                'text' => $this->t->get('admin.alerts.auth_text', ['since' => (string) $this->flags->updatedAt(WtbClient::FLAG_AUTH_ERROR)]),
                'href' => '/admin/sync',
            ];
        }
        $blocked = $this->flags->getJson(ProductSync::FLAG_BLOCKED);
        if ($blocked !== null) {
            $alerts[] = [
                'level' => 'error',
                'title' => $this->t->get('admin.alerts.blocked_title'),
                'text' => $this->t->get('admin.alerts.blocked_text', [
                    'previous' => (int) ($blocked['previous'] ?? 0),
                    'now' => (int) ($blocked['now'] ?? 0),
                    'percent' => (int) ($blocked['max_drop_percent'] ?? 0),
                ]),
                'href' => '/admin/sync',
            ];
        }
        foreach (['products_sync', 'stock_sync'] as $job) {
            $last = $this->db->one("SELECT status, message, started_at FROM job_runs WHERE job = :j AND status != 'skipped' ORDER BY id DESC LIMIT 1", ['j' => $job]);
            if ($last !== null && $last['status'] === 'error' && $blocked === null) {
                $alerts[] = [
                    'level' => 'warning',
                    'title' => $this->t->get('admin.alerts.job_error', ['job' => $this->t->get('admin.sync.jobs.' . $job)]),
                    'text' => mb_substr((string) $last['message'], 0, 300),
                    'href' => '/admin/sync',
                ];
            }
        }

        return array_merge($alerts, $this->orders());
    }

    /**
     * Ordini e fornitore: kill switch, ordini al fornitore in errore o annullati dal fornitore, confermati senza
     * ordine al fornitore, differenze segnalate, webhook sconosciuti o non elaborati, preventivi non verificati.
     *
     * @return list<array{level: string, title: string, text: string, href: string}>
     */
    private function orders(): array
    {
        $alerts = [];
        $mode = $this->supplierOrders->mode();
        if (!$this->supplierOrders->enabled()) {
            $alerts[] = ['level' => 'warning', 'title' => $this->t->get('admin.alerts.orders_disabled_title'), 'text' => $this->t->get('admin.alerts.orders_disabled_text'), 'href' => '/admin/ordini'];
        }
        // Ultimo ordine al fornitore in errore, ordine del cliente ancora aperto.
        $errors = $this->db->column(
            "SELECT s.order_id FROM supplier_orders s JOIN orders o ON o.id = s.order_id
             WHERE s.status = 'error' AND o.status IN ('pending', 'confirmed')
               AND s.id = (SELECT MAX(s2.id) FROM supplier_orders s2 WHERE s2.order_id = s.order_id)
             ORDER BY s.order_id",
        );
        if ($errors !== []) {
            $alerts[] = $this->orderAlert('error', 'supplier_error', $errors);
        }
        // Annullato dal fornitore mentre l'ordine del cliente è aperto: rimborso e comunicazione li decide l'admin.
        $cancelled = $this->db->column(
            "SELECT s.order_id FROM supplier_orders s JOIN orders o ON o.id = s.order_id
             WHERE s.status = 'cancelled' AND o.status IN ('pending', 'confirmed')
               AND s.id = (SELECT MAX(s2.id) FROM supplier_orders s2 WHERE s2.order_id = s.order_id)
             ORDER BY s.order_id",
        );
        if ($cancelled !== []) {
            $alerts[] = $this->orderAlert('error', 'supplier_cancelled', $cancelled);
        }
        // Confermati (pagati dal cliente) senza alcun ordine al fornitore nella modalità attiva.
        $missing = $this->db->column(
            "SELECT o.id FROM orders o WHERE o.status = 'confirmed' AND o.mode = :m
               AND NOT EXISTS (SELECT 1 FROM supplier_orders s WHERE s.order_id = o.id)
             ORDER BY o.id",
            ['m' => $mode],
        );
        if ($missing !== []) {
            $alerts[] = $this->orderAlert('warning', 'supplier_missing', $missing);
        }
        $warned = $this->db->column(
            "SELECT DISTINCT s.order_id FROM supplier_orders s JOIN orders o ON o.id = s.order_id
             WHERE s.warnings != '[]' AND s.status != 'cancelled' AND o.status != 'cancelled' AND s.updated_at > :t
             ORDER BY s.order_id",
            ['t' => $this->clock->isoAgo(14 * 86400)],
        );
        if ($warned !== []) {
            $alerts[] = $this->orderAlert('warning', 'supplier_warnings', $warned);
        }
        $unknown = $this->db->int("SELECT COUNT(*) FROM webhook_events WHERE outcome = 'unknown_order' AND received_at > :t", ['t' => $this->clock->isoAgo(7 * 86400)]);
        if ($unknown > 0) {
            $alerts[] = ['level' => 'warning', 'title' => $this->t->get('admin.alerts.webhook_unknown_title'), 'text' => $this->t->get('admin.alerts.webhook_unknown_text', ['n' => $unknown]), 'href' => '/admin/webhook?esito=unknown_order'];
        }
        $failed = $this->db->int("SELECT COUNT(*) FROM webhook_events WHERE outcome = 'error' AND received_at > :t", ['t' => $this->clock->isoAgo(7 * 86400)]);
        if ($failed > 0) {
            $alerts[] = ['level' => 'error', 'title' => $this->t->get('admin.alerts.webhook_error_title'), 'text' => $this->t->get('admin.alerts.webhook_error_text', ['n' => $failed]), 'href' => '/admin/webhook?esito=error'];
        }
        $unverified = $this->db->column("SELECT id FROM orders WHERE status = 'pending' AND quote_unverified = 1 ORDER BY id");
        if ($unverified !== []) {
            $alerts[] = $this->orderAlert('warning', 'quote_unverified', $unverified);
        }

        return $alerts;
    }

    /**
     * @param list<mixed> $ids
     * @return array{level: string, title: string, text: string, href: string}
     */
    private function orderAlert(string $level, string $key, array $ids): array
    {
        $ids = array_map('intval', $ids);
        $shown = array_slice($ids, 0, 10);

        return [
            'level' => $level,
            'title' => $this->t->get('admin.alerts.' . $key . '_title'),
            'text' => $this->t->get('admin.alerts.' . $key . '_text', ['n' => count($ids), 'ids' => implode(', ', $shown) . (count($ids) > 10 ? '…' : '')]),
            'href' => count($ids) === 1 ? '/admin/ordini/' . $ids[0] . '#fornitore' : '/admin/ordini',
        ];
    }
}
