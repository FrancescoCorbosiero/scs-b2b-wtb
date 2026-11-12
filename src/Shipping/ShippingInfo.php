<?php

declare(strict_types=1);

namespace App\Shipping;

use App\Supplier\ShippingSnapshot;
use App\Support\Db;

/**
 * Valori reali per le pagine pubbliche e il checkout: zone e tariffe, tempi di partenza letti dalle offerte,
 * paesi extra UE dove dazi e oneri sono a carico del destinatario (dall'ultima fotografia del fornitore).
 */
final class ShippingInfo
{
    public function __construct(
        private readonly Db $db,
        private readonly ZoneRepository $zones,
        private readonly CountryRepository $countries,
        private readonly ShippingSnapshot $snapshot,
    ) {
    }

    /** @return list<array{zone: ShippingZone, countries: list<string>}> */
    public function zonesWithCountries(): array
    {
        $out = [];
        foreach ($this->zones->all() as $row) {
            $zone = ShippingZone::fromRow($row);
            $codes = [];
            foreach ($this->countries->all() as $code => $country) {
                if ((int) $country['served'] === 1 && $country['zone'] === $zone->code) {
                    $codes[] = (string) $code;
                }
            }
            if ($codes !== []) {
                $out[] = ['zone' => $zone, 'countries' => $codes];
            }
        }

        return $out;
    }

    /** Tempi di partenza per tipo di stock, i più frequenti tra le offerte disponibili. @return array<string, string> */
    public function departureDays(): array
    {
        $out = ['immediate' => '1-2', 'extended' => '3-5'];
        $seen = [];
        foreach ($this->db->all(
            "SELECT stock_type, ships_in_days, COUNT(*) AS n FROM offers WHERE available > 0 AND ships_in_days != ''
             GROUP BY stock_type, ships_in_days ORDER BY n DESC",
        ) as $row) {
            $type = (string) $row['stock_type'];
            if (!isset($seen[$type])) {
                $out[$type] = (string) $row['ships_in_days'];
                $seen[$type] = true;
            }
        }

        return $out;
    }

    /** Paesi serviti in cui dazi e oneri doganali sono a carico del destinatario. @return list<string> */
    public function dutiesCountries(): array
    {
        $codes = [];
        foreach ($this->snapshot->latest()['profile']->zones ?? [] as $zone) {
            if ($zone->dutiesPaidByRecipient) {
                foreach ($zone->countries as $code) {
                    if ($this->countries->isServed($code)) {
                        $codes[] = $code;
                    }
                }
            }
        }
        if ($codes === []) {
            // Senza fotografia: per prudenza tutti i paesi serviti fuori dalla UE.
            foreach ($this->countries->all() as $code => $country) {
                if ((int) $country['served'] === 1 && (int) $country['is_eu'] === 0) {
                    $codes[] = (string) $code;
                }
            }
        }
        sort($codes);

        return array_values(array_unique($codes));
    }

    public function hasDuties(string $country): bool
    {
        return in_array(strtoupper($country), $this->dutiesCountries(), true);
    }
}
