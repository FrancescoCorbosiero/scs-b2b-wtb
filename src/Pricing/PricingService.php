<?php

declare(strict_types=1);

namespace App\Pricing;

/**
 * Prezzo di vendita = arrotonda(costo + margine), sempre per eccesso (docs/04). Servizio puro.
 */
final class PricingService
{
    public static function salePrice(int $costCents, MarginSpec $spec, string $rounding): int
    {
        return match ($spec->type) {
            // costo × (1 + bp/10000), poi per eccesso all'unità.
            'percent' => Rounding::ceilTo($costCents * (10000 + $spec->value), 10000, $rounding),
            'fixed' => Rounding::ceilTo($costCents + $spec->value, 1, $rounding),
            // Prezzo esatto: ignora costo e arrotondamento (l'admin vede un avviso se è sotto costo).
            'fixed_price' => $spec->value,
            default => throw new \InvalidArgumentException('Tipo di margine sconosciuto: ' . $spec->type),
        };
    }
}
