<?php

declare(strict_types=1);

namespace App\Pricing;

/**
 * Regola margine di /admin/margini (docs/04). Le regole SKU vengono sempre per prime, poi le altre per
 * priorità; ogni regola può valere per un solo regime e/o un solo tipo di stock.
 */
final class MarginRule
{
    public const MATCH_TYPES = ['sku', 'brand', 'name', 'line', 'gender', 'category', 'size_category'];

    public function __construct(
        public readonly int $id,
        public readonly int $priority,
        public readonly string $matchType,
        public readonly string $matchValue,
        public readonly ?string $regime,
        public readonly ?string $stockType,
        public readonly string $marginType,
        public readonly int $marginValue,
        public readonly bool $isActive,
        public readonly string $note = '',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['priority'],
            (string) $row['match_type'],
            (string) $row['match_value'],
            $row['regime'] === null ? null : (string) $row['regime'],
            $row['stock_type'] === null ? null : (string) $row['stock_type'],
            (string) $row['margin_type'],
            (int) $row['margin_value'],
            (int) $row['is_active'] === 1,
            (string) ($row['note'] ?? ''),
        );
    }

    public function spec(): MarginSpec
    {
        return new MarginSpec($this->marginType, $this->marginValue, $this->id);
    }

    /** Ordine di valutazione: SKU prima, poi priorità, poi tipo, poi id. */
    public function sortKey(): string
    {
        $typeOrder = array_search($this->matchType, self::MATCH_TYPES, true);

        return sprintf('%d|%08d|%d|%08d', $this->matchType === 'sku' ? 0 : 1, $this->priority + 10000000, (int) $typeOrder, $this->id);
    }

    /** @param array<string, mixed> $product riga di products (sku, name, brand, line, gender, category_path, size_category) */
    public function matches(array $product, string $regime, string $stockType): bool
    {
        if (($this->regime !== null && $this->regime !== $regime) || ($this->stockType !== null && $this->stockType !== $stockType)) {
            return false;
        }
        $value = mb_strtolower(trim($this->matchValue));
        if ($value === '') {
            return false;
        }
        $field = static fn (string $key): string => mb_strtolower(trim((string) ($product[$key] ?? '')));

        return match ($this->matchType) {
            'sku' => in_array($field('sku'), array_map(static fn (string $s): string => mb_strtolower(trim($s)), explode(',', $this->matchValue)), true),
            'brand' => $field('brand') === $value,
            'name' => str_contains($field('name'), $value),
            'line' => $field('line') === $value,
            'gender' => $field('gender') === $value,
            'size_category' => $field('size_category') === $value,
            'category' => str_contains(mb_strtolower(implode(' / ', self::path($product))), $value),
            default => false,
        };
    }

    /** @param array<string, mixed> $product @return list<string> */
    private static function path(array $product): array
    {
        $path = $product['category_path'] ?? [];
        if (is_string($path)) {
            $path = json_decode($path, true);
        }

        return is_array($path) ? array_values(array_map('strval', $path)) : [];
    }
}
