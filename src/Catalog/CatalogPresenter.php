<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Pricing\Tier;
use App\Settings\SettingsService;
use App\Support\Db;

/**
 * Dati del catalogo per le viste del cliente: SOLO prezzi di vendita, mai costi (regola d'oro 1), mai URL o
 * nomi del fornitore (regola d'oro 2). In vetrina ($showPrices = false) i prezzi spariscono dai dati, non dal CSS.
 */
final class CatalogPresenter
{
    public function __construct(
        private readonly Db $db,
        private readonly SettingsService $settings,
        private readonly ProductAggregates $aggregates,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows righe di products
     * @return list<array<string, mixed>>
     */
    public function cards(array $rows, bool $showPrices, string $sizeSystem): array
    {
        if ($rows === []) {
            return [];
        }
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        [$in, $params] = self::in($ids);
        $images = [];
        foreach ($this->db->all('SELECT product_id, position, source_url FROM product_images WHERE position = 0 AND product_id IN (' . $in . ')', $params) as $img) {
            $images[(int) $img['product_id']] = ImageService::publicUrl((int) $img['product_id'], 0, (string) $img['source_url'], 400);
        }
        $sizes = [];
        $sellable = $this->aggregates->sellableCondition();
        foreach ($this->db->all(
            'SELECT v.product_id, v.size_eu, v.size_us, v.size_uk, SUM(o.available) AS available
             FROM variants v JOIN offers o ON o.variant_id = v.id
             WHERE v.product_id IN (' . $in . ') AND ' . $sellable . '
             GROUP BY v.id ORDER BY v.size_sort, v.id',
            $params,
        ) as $v) {
            $sizes[(int) $v['product_id']][] = ['label' => self::sizeLabel($v, $sizeSystem), 'available' => (int) $v['available']];
        }

        return array_map(fn (array $r): array => [
            'id' => (int) $r['id'],
            'sku' => (string) $r['sku'],
            'name' => (string) $r['name'],
            'brand' => (string) $r['brand'],
            'line' => (string) $r['line'],
            'image' => $images[(int) $r['id']] ?? null,
            'availability' => $this->level((int) $r['total_available']),
            'total' => (int) $r['total_available'],
            'price_from' => $showPrices && $r['min_price_cents'] !== null ? (int) $r['min_price_cents'] : null,
            'price_regime' => $showPrices && $r['min_price_regime'] !== null ? (string) $r['min_price_regime'] : null,
            'has_immediate' => (int) $r['has_immediate'] === 1,
            'featured' => (int) $r['is_featured'] === 1,
            'sold_out' => (int) $r['total_available'] === 0,
            'sizes' => $sizes[(int) $r['id']] ?? [],
        ], $rows);
    }

    /**
     * Scheda rapida: per ogni taglia una riga per regime in vendita, con le fasce (docs/07).
     *
     * @return array<string, mixed>|null
     */
    public function detail(string $sku, bool $showPrices, string $sizeSystem): ?array
    {
        $product = $this->db->one('SELECT * FROM products WHERE sku = :sku AND is_active = 1', ['sku' => $sku]);
        if ($product === null) {
            return null;
        }
        $id = (int) $product['id'];
        $images = array_map(
            static fn (array $img): array => [
                'thumb' => ImageService::publicUrl($id, (int) $img['position'], (string) $img['source_url'], 400),
                'large' => ImageService::publicUrl($id, (int) $img['position'], (string) $img['source_url'], 800),
            ],
            $this->db->all('SELECT position, source_url FROM product_images WHERE product_id = :p ORDER BY position', ['p' => $id]),
        );
        $sellable = $this->aggregates->sellableCondition();
        $rows = $this->db->all(
            'SELECT v.id AS variant_id, v.size_eu, v.size_us, v.size_uk, v.ean, o.vat_regime, o.stock_type, o.available, o.sale_price_cents, o.ships_in_days
             FROM variants v JOIN offers o ON o.variant_id = v.id
             WHERE v.product_id = :p AND ' . $sellable . '
             ORDER BY v.size_sort, v.id, o.vat_regime, o.stock_type DESC',
            ['p' => $id],
        );
        $units = [];
        foreach ($rows as $row) {
            $key = $row['variant_id'] . '-' . $row['vat_regime'];
            $units[$key] ??= [
                'key' => $key,
                'variant_id' => (int) $row['variant_id'],
                'regime' => (string) $row['vat_regime'],
                'size' => self::sizeLabel($row, $sizeSystem),
                'size_eu' => (string) $row['size_eu'],
                'size_us' => (string) $row['size_us'],
                'size_uk' => (string) $row['size_uk'],
                'tiers' => [],
                'max' => 0,
            ];
            $units[$key]['tiers'][] = [
                'stock_type' => (string) $row['stock_type'],
                'available' => (int) $row['available'],
                'price' => $showPrices ? (int) $row['sale_price_cents'] : null,
                'ships_in_days' => (string) $row['ships_in_days'],
            ];
            $units[$key]['max'] += (int) $row['available'];
        }
        foreach ($units as &$unit) {
            usort($unit['tiers'], static fn (array $a, array $b): int => ($a['stock_type'] === 'immediate' ? 0 : 1) <=> ($b['stock_type'] === 'immediate' ? 0 : 1));
        }
        unset($unit);
        $path = json_decode((string) $product['category_path'], true);

        return [
            'id' => $id,
            'sku' => (string) $product['sku'],
            'name' => (string) $product['name'],
            'brand' => (string) $product['brand'],
            'line' => (string) $product['line'],
            'gender' => (string) $product['gender'],
            'size_category' => (string) $product['size_category'],
            'category_path' => is_array($path) ? $path : [],
            'description' => (string) $product['description'],
            'featured' => (int) $product['is_featured'] === 1,
            'availability' => $this->level((int) $product['total_available']),
            'total' => (int) $product['total_available'],
            'sold_out' => (int) $product['total_available'] === 0,
            'price_from' => $showPrices && $product['min_price_cents'] !== null ? (int) $product['min_price_cents'] : null,
            'images' => $images,
            'units' => array_values($units),
        ];
    }

    /** @param array<string, mixed> $unit @return list<Tier> */
    public static function tiersOf(array $unit): array
    {
        return array_map(
            static fn (array $t): Tier => new Tier((string) $t['stock_type'], (int) $t['available'], $t['price'] === null ? null : (int) $t['price'], (string) $t['ships_in_days']),
            $unit['tiers'],
        );
    }

    public function level(int $total): string
    {
        if ($total <= 0) {
            return 'out';
        }
        if ($total >= $this->settings->int('catalog.availability_high_min')) {
            return 'high';
        }

        return $total <= $this->settings->int('catalog.availability_low_max') ? 'low' : 'medium';
    }

    /** Taglia nel sistema scelto; se manca (es. US vuota) si mostra la EU, marcata come tale. @param array<string, mixed> $variant */
    public static function sizeLabel(array $variant, string $system): string
    {
        $value = (string) ($variant['size_' . $system] ?? '');

        return $value === '' ? (string) $variant['size_eu'] . ($system === 'eu' ? '' : ' EU') : $value;
    }

    /** @param list<int> $ids @return array{0: string, 1: array<string, int>} */
    private static function in(array $ids): array
    {
        $placeholders = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $placeholders[] = ':id' . $i;
            $params['id' . $i] = $id;
        }

        return [implode(',', $placeholders), $params];
    }
}
