<?php

declare(strict_types=1);

namespace App\Admin;

use App\Support\Db;
use App\Support\Translator;

/** Riquadri numerici della dashboard: ogni modulo aggiunge i suoi. */
final class DashboardCards
{
    public function __construct(
        private readonly Db $db,
        private readonly Translator $t,
    ) {
    }

    /** @return list<array{label: string, value: string|int, href: string, note?: string}> */
    public function all(): array
    {
        $last = $this->db->value("SELECT finished_at FROM job_runs WHERE job = 'stock_sync' AND status = 'ok' ORDER BY id DESC LIMIT 1");

        return [
            ['label' => $this->t->get('admin.dashboard.cards.products'), 'value' => $this->db->int('SELECT COUNT(*) FROM products WHERE is_active = 1 AND total_available > 0'), 'href' => '/admin/sync'],
            ['label' => $this->t->get('admin.dashboard.cards.customers'), 'value' => $this->db->int('SELECT COUNT(*) FROM users WHERE is_active = 1 AND password_hash IS NOT NULL'), 'href' => '/admin/clienti'],
            ['label' => $this->t->get('admin.dashboard.cards.last_stock'), 'value' => $last === null ? '—' : substr((string) $last, 11, 5) . ' UTC', 'href' => '/admin/sync', 'note' => $last === null ? $this->t->get('admin.dashboard.cards.never') : substr((string) $last, 0, 10)],
        ];
    }
}
