<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Support\Clock;
use App\Support\Db;

final class MarginRuleRepository
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<MarginRule> in ordine di valutazione */
    public function all(bool $onlyActive = false): array
    {
        $rows = $this->db->all('SELECT * FROM margin_rules' . ($onlyActive ? ' WHERE is_active = 1' : ''));
        $rules = array_map(MarginRule::fromRow(...), $rows);
        usort($rules, static fn (MarginRule $a, MarginRule $b): int => strcmp($a->sortKey(), $b->sortKey()));

        return $rules;
    }

    public function find(int $id): ?MarginRule
    {
        $row = $this->db->one('SELECT * FROM margin_rules WHERE id = :id', ['id' => $id]);

        return $row === null ? null : MarginRule::fromRow($row);
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, string $by): int
    {
        $now = $this->clock->iso();

        return $this->db->insert('margin_rules', self::columns($data) + ['created_at' => $now, 'updated_at' => $now, 'updated_by' => $by]);
    }

    /** @param array<string, mixed> $data */
    public function update(int $id, array $data, string $by): void
    {
        $this->db->update('margin_rules', self::columns($data) + ['updated_at' => $this->clock->iso(), 'updated_by' => $by], 'id = :id', ['id' => $id]);
    }

    public function setActive(int $id, bool $active, string $by): void
    {
        $this->db->update('margin_rules', ['is_active' => $active ? 1 : 0, 'updated_at' => $this->clock->iso(), 'updated_by' => $by], 'id = :id', ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM margin_rules WHERE id = :id', ['id' => $id]);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function columns(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'priority', 'match_type', 'match_value', 'regime', 'stock_type', 'margin_type', 'margin_value', 'is_active', 'note',
        ]));
    }
}
