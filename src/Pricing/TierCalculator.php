<?php

declare(strict_types=1);

namespace App\Pricing;

/**
 * Fasce (docs/04 § Unità vendibile e fasce): per una quantità q, prima le paia in pronta consegna, poi
 * quelle in stock esteso, ciascuna col suo prezzo. Servizio puro.
 */
final class TierCalculator
{
    /**
     * @param list<Tier> $tiers
     * @return array{lines: list<array{stock_type: string, quantity: int, unit_price_cents: int, line_total_cents: int, ships_in_days: string}>, quantity: int, total_cents: int, max: int}
     */
    public static function split(array $tiers, int $quantity): array
    {
        $ordered = $tiers;
        usort($ordered, static fn (Tier $a, Tier $b): int => ($a->stockType === 'immediate' ? 0 : 1) <=> ($b->stockType === 'immediate' ? 0 : 1));
        $max = array_sum(array_map(static fn (Tier $t): int => max(0, $t->available), $ordered));
        $left = max(0, min($quantity, $max));
        $lines = [];
        $total = 0;
        foreach ($ordered as $tier) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, max(0, $tier->available));
            if ($take === 0) {
                continue;
            }
            $price = $tier->unitPriceCents ?? 0;
            $lines[] = [
                'stock_type' => $tier->stockType,
                'quantity' => $take,
                'unit_price_cents' => $price,
                'line_total_cents' => $price * $take,
                'ships_in_days' => $tier->shipsInDays,
            ];
            $total += $price * $take;
            $left -= $take;
        }

        return ['lines' => $lines, 'quantity' => array_sum(array_column($lines, 'quantity')), 'total_cents' => $total, 'max' => $max];
    }
}
