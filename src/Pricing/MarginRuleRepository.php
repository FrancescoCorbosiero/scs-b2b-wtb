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

    /**
     * Valori presenti nel catalogo per ogni tipo di regola, dal più frequente (D-020):
     * suggerimenti per il campo "valore" (solo admin).
     *
     * @return array<string, list<array{value: string, count: int}>>
     */
    public function suggestions(int $limit = 200): array
    {
        $out = [];
        foreach (['brand', 'line', 'gender', 'category', 'size_category'] as $column) {
            $rows = $this->db->all(
                "SELECT LOWER({$column}) AS v, COUNT(*) AS n FROM products WHERE is_active = 1 AND {$column} <> ''
                 GROUP BY LOWER({$column}) ORDER BY n DESC, v LIMIT {$limit}",
            );
            $out[$column] = array_map(static fn (array $r): array => ['value' => (string) $r['v'], 'count' => (int) $r['n']], $rows);
        }

        return $out;
    }

    /** Il tipo di regola più usato, proposto per una regola nuova (D-020). */
    public function mostUsedType(): string
    {
        $type = $this->db->value('SELECT match_type FROM margin_rules GROUP BY match_type ORDER BY COUNT(*) DESC, MAX(id) DESC LIMIT 1');

        return is_string($type) && in_array($type, MarginRule::MATCH_TYPES, true) ? $type : 'brand';
    }
}
