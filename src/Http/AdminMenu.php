<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Voci del menu admin, con i contatori di ciò che aspetta un'azione.
 * Aggiungere una pagina admin = una voce qui.
 */
final class AdminMenu
{
    /** @return list<array{href: string, label: string, badge: int}> */
    public function items(): array
    {
        return [
            ['href' => '/admin', 'label' => 'Dashboard', 'badge' => 0],
            ['href' => '/admin/clienti', 'label' => 'Clienti', 'badge' => 0],
            ['href' => '/admin/sync', 'label' => 'Sync', 'badge' => 0],
            ['href' => '/admin/impostazioni', 'label' => 'Impostazioni', 'badge' => 0],
        ];
    }
}
