<?php

declare(strict_types=1);

namespace App\Supplier\Model;

final class SupplierProduct
{
    /**
     * @param list<string> $categoryPath
     * @param list<string> $images URL sorgente (mai mostrati al cliente: si servono dal nostro dominio)
     * @param list<SupplierVariant> $variants
     */
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly string $brand,
        public readonly string $category,
        public readonly string $line,
        public readonly string $gender,
        public readonly array $categoryPath,
        public readonly string $description,
        public readonly array $images,
        public readonly array $variants,
    ) {
    }
}
