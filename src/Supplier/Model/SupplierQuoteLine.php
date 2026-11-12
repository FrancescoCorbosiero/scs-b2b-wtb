<?php

declare(strict_types=1);

namespace App\Supplier\Model;

final class SupplierQuoteLine
{
    public function __construct(
        public readonly string $sku,
        public readonly string $sizeEu,
        public readonly string $regime,
        public readonly int $quantity,
        public readonly int $unitCostCents,
        public readonly string $stockType,
    ) {
    }
}
