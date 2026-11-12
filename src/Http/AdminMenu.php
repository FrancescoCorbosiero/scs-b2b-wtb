<?php

declare(strict_types=1);

namespace App\Http;

use App\Support\Clock;
use App\Support\Db;
use App\Support\Translator;

/**
 * Voci del menu admin, con i contatori di ciò che aspetta un'azione.
 * Aggiungere una pagina admin = una voce qui.
 */
final class AdminMenu
{
    public function __construct(
        private readonly Db $db,
        private readonly Translator $t,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<array{href: string, label: string, badge: int}> */
    public function items(): array
    {
        return [
            ['href' => '/admin', 'label' => $this->t->get('admin.nav.dashboard'), 'badge' => 0],
            ['href' => '/admin/ordini', 'label' => $this->t->get('admin.nav.orders'), 'badge' => $this->count("SELECT COUNT(*) FROM orders WHERE status = 'pending'")],
            ['href' => '/admin/ordini-fornitore', 'label' => $this->t->get('admin.nav.supplier_orders'), 'badge' => $this->count(
                "SELECT COUNT(*) FROM supplier_orders WHERE status IN ('received', 'confirmed') AND is_paid = 0 AND payment_method = 'bank_transfer'",
            )],
            ['href' => '/admin/webhook', 'label' => $this->t->get('admin.nav.webhooks'), 'badge' => $this->count(
                "SELECT COUNT(*) FROM webhook_events WHERE outcome IN ('unknown_order', 'error') AND received_at > :t",
                ['t' => $this->clock->isoAgo(7 * 86400)],
            )],
            ['href' => '/admin/clienti', 'label' => $this->t->get('admin.nav.customers'), 'badge' => 0],
            ['href' => '/admin/richieste-profilo', 'label' => $this->t->get('admin.nav.requests'), 'badge' => $this->count("SELECT COUNT(*) FROM account_requests WHERE status = 'pending'")],
            ['href' => '/admin/prodotti', 'label' => $this->t->get('admin.nav.products'), 'badge' => 0],
            ['href' => '/admin/margini', 'label' => $this->t->get('admin.nav.margins'), 'badge' => 0],
            ['href' => '/admin/spedizione', 'label' => $this->t->get('admin.nav.shipping'), 'badge' => 0],
            ['href' => '/admin/sync', 'label' => 'Sync', 'badge' => 0],
            ['href' => '/admin/impostazioni', 'label' => $this->t->get('admin.nav.settings'), 'badge' => 0],
        ];
    }

    /** @param array<string, mixed> $params */
    private function count(string $sql, array $params = []): int
    {
        try {
            return $this->db->int($sql, $params);
        } catch (\Throwable) {
            return 0;
        }
    }
}
