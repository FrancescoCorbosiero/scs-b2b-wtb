<?php

declare(strict_types=1);

namespace App\Shipping;

use App\Support\Clock;
use App\Support\Db;

/** Zone di spedizione al cliente e tariffe (tabella shipping_zones, D-006). */
final class ZoneRepository
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->all('SELECT * FROM shipping_zones ORDER BY sort_order, zone');
    }

    /** @return array<string, mixed>|null */
    public function find(string $zone): ?array
    {
        return $this->db->one('SELECT * FROM shipping_zones WHERE zone = :z', ['z' => $zone]);
    }

    public function toZone(string $zone): ShippingZone
    {
        $row = $this->find($zone) ?? throw new \InvalidArgumentException('Zona sconosciuta: ' . $zone);

        return ShippingZone::fromRow($row);
    }

    /** @param array<string, mixed> $data */
    public function update(string $zone, array $data): void
    {
        $allowed = array_intersect_key($data, array_flip(['name_it', 'name_en', 'fee_cents', 'free_from_items', 'transit_days_min', 'transit_days_max', 'sort_order']));
        $allowed['updated_at'] = $this->clock->iso();
        $this->db->update('shipping_zones', $allowed, 'zone = :z', ['z' => $zone]);
    }

    /** @param array<string, mixed> $data */
    public function create(string $zone, array $data): void
    {
        $this->db->insert('shipping_zones', [
            'zone' => $zone,
            'name_it' => $data['name_it'],
            'name_en' => $data['name_en'],
            'fee_cents' => $data['fee_cents'],
            'free_from_items' => $data['free_from_items'] ?? null,
            'transit_days_min' => $data['transit_days_min'],
            'transit_days_max' => $data['transit_days_max'],
            'sort_order' => $data['sort_order'] ?? 100,
            'updated_at' => $this->clock->iso(),
        ]);
    }

    public function delete(string $zone): bool
    {
        if ($this->db->int('SELECT COUNT(*) FROM countries WHERE zone = :z', ['z' => $zone]) > 0) {
            return false;
        }

        return $this->db->run('DELETE FROM shipping_zones WHERE zone = :z', ['z' => $zone])->rowCount() === 1;
    }
}
