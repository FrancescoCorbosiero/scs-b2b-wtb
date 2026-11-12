<?php

declare(strict_types=1);

namespace App\Supplier\Model;

/** Una taglia di un prodotto. Le taglie sono sempre stringhe ("38.5", "36 2/3", "5.5Y"). */
final class SupplierVariant
{
    /** @param list<SupplierOffer> $offers */
    public function __construct(
        public readonly int $variantId,
        public readonly string $sizeEu,
        public readonly string $sizeUs,
        public readonly string $sizeUk,
        public readonly string $ean,
        public readonly array $offers,
    ) {
    }
}
