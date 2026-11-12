<?php

declare(strict_types=1);

namespace App\Admin;

use App\Support\Clock;
use App\Support\Db;
use App\Support\Translator;

/** Riquadri numerici della dashboard: ogni modulo aggiunge i suoi. */
final class DashboardCards
{
    public function __construct(
        private readonly Db $db,
        private readonly Translator $t,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<array{label: string, value: string|int, href: string, note?: string}> */
    public function all(): array
    {
        $last = $this->db->value("SELECT finished_at FROM job_runs WHERE job = 'stock_sync' AND status = 'ok' ORDER BY id DESC LIMIT 1");

        $balance = $this->db->int(
            "SELECT COALESCE(SUM(shipping_cents - wtb_shipping_cost_cents), 0) FROM orders
             WHERE status IN ('confirmed', 'shipped') AND wtb_shipping_cost_cents IS NOT NULL AND created_at > :t",
            ['t' => $this->clock->isoAgo(30 * 86400)],
        );

        return [
            ['label' => $this->t->get('admin.dashboard.cards.pending_orders'), 'value' => $this->db->int("SELECT COUNT(*) FROM orders WHERE status = 'pending'"), 'href' => '/admin/ordini?stato=pending'],
            ['label' => $this->t->get('admin.dashboard.cards.shipping_balance'), 'value' => \App\Support\Money::format($balance), 'href' => '/admin/ordini?stato=confirmed', 'note' => $this->t->get('admin.dashboard.cards.shipping_balance_note')],
            ['label' => $this->t->get('admin.dashboard.cards.products'), 'value' => $this->db->int('SELECT COUNT(*) FROM products WHERE is_active = 1 AND total_available > 0'), 'href' => '/admin/sync'],
            ['label' => $this->t->get('admin.dashboard.cards.customers'), 'value' => $this->db->int('SELECT COUNT(*) FROM users WHERE is_active = 1 AND password_hash IS NOT NULL'), 'href' => '/admin/clienti'],
            ['label' => $this->t->get('admin.dashboard.cards.last_stock'), 'value' => $last === null ? '—' : substr((string) $last, 11, 5) . ' UTC', 'href' => '/admin/sync', 'note' => $last === null ? $this->t->get('admin.dashboard.cards.never') : substr((string) $last, 0, 10)],
        ];
    }
}
