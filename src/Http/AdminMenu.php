<?php

declare(strict_types=1);

namespace App\Http;

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
    ) {
    }

    /** @return list<array{href: string, label: string, badge: int}> */
    public function items(): array
    {
        return [
            ['href' => '/admin', 'label' => $this->t->get('admin.nav.dashboard'), 'badge' => 0],
            ['href' => '/admin/clienti', 'label' => $this->t->get('admin.nav.customers'), 'badge' => 0],
            ['href' => '/admin/richieste-profilo', 'label' => $this->t->get('admin.nav.requests'), 'badge' => $this->count("SELECT COUNT(*) FROM account_requests WHERE status = 'pending'")],
            ['href' => '/admin/prodotti', 'label' => $this->t->get('admin.nav.products'), 'badge' => 0],
            ['href' => '/admin/margini', 'label' => $this->t->get('admin.nav.margins'), 'badge' => 0],
            ['href' => '/admin/sync', 'label' => 'Sync', 'badge' => 0],
            ['href' => '/admin/impostazioni', 'label' => $this->t->get('admin.nav.settings'), 'badge' => 0],
        ];
    }

    private function count(string $sql): int
    {
        try {
            return $this->db->int($sql);
        } catch (\Throwable) {
            return 0;
        }
    }
}
