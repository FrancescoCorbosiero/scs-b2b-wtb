<?php

declare(strict_types=1);

namespace App\Supplier;

use App\Supplier\Model\ShippingProfile;

/** Ultima fotografia del profilo di spedizione del fornitore, salvata dal sync completo (solo dati interni). */
interface ShippingSnapshot
{
    /** @return array{profile: ShippingProfile, fetched_at: string}|null null se il sync non l'ha mai letta */
    public function latest(): ?array;
}
