<?php

declare(strict_types=1);

namespace App\Supplier\Model;

final class SupplierOrderResult
{
    /** @param bool $created true = 201 (nuovo), false = 200 (reference già presente: stesso ordine) */
    public function __construct(
        public readonly SupplierOrderData $order,
        public readonly bool $created,
        public readonly int $attempts,
    ) {
    }
}
