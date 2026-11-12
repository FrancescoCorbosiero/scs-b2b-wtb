<?php

declare(strict_types=1);

namespace App\Shipping;

/** Tariffa al cliente: fissa per ordine e zona, gratis da N paia se impostato (D-006, docs/05). Servizio puro. */
final class ShippingCalculator
{
    public static function fee(int $feeCents, ?int $freeFromItems, int $pairs): int
    {
        if ($pairs <= 0) {
            return 0;
        }

        return $freeFromItems !== null && $pairs >= $freeFromItems ? 0 : $feeCents;
    }
}
