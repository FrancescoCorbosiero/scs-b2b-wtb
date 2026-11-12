<?php

declare(strict_types=1);

namespace App\Supplier\Model;

/** Profilo del fornitore da /shipping/: tipo di profilo, zone, trasporto dello stock esteso, ordine minimo. */
final class ShippingProfile
{
    /**
     * @param list<SupplierZone> $zones
     * @param array<int, int> $extendedTransport paia estese => costo in centesimi
     * @param array<string, mixed> $raw risposta originale (per la fotografia in admin)
     */
    public function __construct(
        public readonly string $profile,
        public readonly array $zones,
        public readonly array $extendedTransport,
        public readonly ?int $extendedFreeFrom,
        public readonly ?int $minOrderPieces,
        public readonly array $raw,
    ) {
    }

    public function zoneFor(string $country): ?SupplierZone
    {
        foreach ($this->zones as $zone) {
            if (in_array(strtoupper($country), $zone->countries, true)) {
                return $zone;
            }
        }

        return null;
    }

    /** Costo del trasporto dello stock esteso per N paia estese. */
    public function extendedTransportFor(int $pieces): int
    {
        if ($pieces <= 0 || ($this->extendedFreeFrom !== null && $pieces >= $this->extendedFreeFrom)) {
            return 0;
        }
        if (isset($this->extendedTransport[$pieces])) {
            return $this->extendedTransport[$pieces];
        }
        $known = array_filter($this->extendedTransport, static fn (int $p): bool => $p <= $pieces, ARRAY_FILTER_USE_KEY);

        return $known === [] ? 0 : $known[max(array_keys($known))];
    }
}
