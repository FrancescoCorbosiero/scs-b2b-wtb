<?php

declare(strict_types=1);

namespace App\Pricing;

/**
 * Unico punto degli arrotondamenti sui soldi (regola d'oro 7). Solo interi, mai float.
 */
final class Rounding
{
    /** Unità di arrotondamento del prezzo di vendita, in centesimi (prices.rounding). */
    public const UNITS = ['whole' => 100, 'half' => 50, 'none' => 1];

    /** ceil(numerator / denominator) arrotondato per eccesso all'unità della modalità. Numeratore ≥ 0. */
    public static function ceilTo(int $numerator, int $denominator, string $mode): int
    {
        $unit = self::UNITS[$mode] ?? 100;
        $den = $denominator * $unit;

        return intdiv($numerator + $den - 1, $den) * $unit;
    }

    /** Percentuale (in punti base) di un importo, arrotondata al centesimo "half up" (IVA). */
    public static function percentHalfUp(int $cents, int $basisPoints): int
    {
        $sign = $cents < 0 ? -1 : 1;

        return $sign * intdiv(abs($cents) * $basisPoints + 5000, 10000);
    }
}
