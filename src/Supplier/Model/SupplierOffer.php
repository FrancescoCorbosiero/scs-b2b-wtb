<?php

declare(strict_types=1);

namespace App\Supplier\Model;

/** Offerta = taglia × regime IVA × tipo di stock. Il costo è RISERVATO (regola d'oro 1). */
final class SupplierOffer
{
    public const REGIMES = ['margin', 'ordinary'];
    public const STOCK_TYPES = ['immediate', 'extended'];

    public function __construct(
        public readonly string $regime,
        public readonly string $stockType,
        public readonly int $available,
        public readonly int $costCents,
        public readonly string $shipsInDays,
    ) {
    }
}
