<?php

declare(strict_types=1);

namespace App\Shipping;

/** Consegna stimata = partenza + transito, in giorni lavorativi (docs/05). Servizio puro. */
final class DeliveryEstimate
{
    /** "1-2" → [1, 2]; "3" → [3, 3]; testo non riconosciuto → null. @return array{0: int, 1: int}|null */
    public static function parseRange(string $days): ?array
    {
        if (preg_match('/^\s*(\d{1,2})\s*(?:-\s*(\d{1,2}))?\s*$/', $days, $m) !== 1) {
            return null;
        }
        $min = (int) $m[1];
        $max = isset($m[2]) ? (int) $m[2] : $min;

        return $min <= $max ? [$min, $max] : [$max, $min];
    }

    /**
     * Per un ordine misto vale la partenza più lunga (docs/05).
     *
     * @param list<string> $shipsInDays
     * @return array{0: int, 1: int}|null
     */
    public static function departure(array $shipsInDays): ?array
    {
        $min = null;
        $max = null;
        foreach ($shipsInDays as $days) {
            $range = self::parseRange($days);
            if ($range === null) {
                continue;
            }
            $min = max($min ?? 0, $range[0]);
            $max = max($max ?? 0, $range[1]);
        }

        return $min === null || $max === null ? null : [$min, $max];
    }

    /** @param array{0: int, 1: int} $departure @return array{0: int, 1: int} */
    public static function delivery(array $departure, int $transitMin, int $transitMax): array
    {
        return [$departure[0] + $transitMin, $departure[1] + $transitMax];
    }
}
