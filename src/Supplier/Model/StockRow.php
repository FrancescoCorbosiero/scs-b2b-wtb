<?php

declare(strict_types=1);

namespace App\Supplier\Model;

final class StockRow
{
    public function __construct(
        public readonly string $sku,
        public readonly int $variantId,
        public readonly string $sizeEu,
        public readonly string $regime,
        public readonly string $stockType,
        public readonly int $available,
        public readonly int $costCents,
        public readonly string $shipsInDays,
    ) {
    }
}
