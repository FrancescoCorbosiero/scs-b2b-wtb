<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Settings\SettingsService;
use App\Support\Db;

/**
 * Ricerca nel catalogo: filtri componibili, ordinamenti, "carica altri", faccette.
 * Solo dati del nostro database (mai chiamate al fornitore durante la navigazione).
 */
final class CatalogQuery
{
    public function __construct(
        private readonly Db $db,
        private readonly SettingsService $settings,
        private readonly ProductAggregates $aggregates,
    ) {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, has_more: bool}
     */
    public function search(CatalogFilters $f, int $offset, int $limit): array
    {
        [$where, $params] = $this->where($f);
        $total = $this->db->int('SELECT COUNT(*) FROM products p WHERE ' . $where, $params);
        $order = $this->order($f, $params);
        $rows = $this->db->all(
            'SELECT p.* FROM products p WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset],
        );

        return ['rows' => $rows, 'total' => $total, 'has_more' => $offset + count($rows) < $total];
    }

    /** Tutte le righe che rispettano i filtri (export). @return list<array<string, mixed>> */
    public function all(CatalogFilters $f): array
    {
        [$where, $params] = $this->where($f);
        $order = $this->order($f, $params);

        return $this->db->all('SELECT p.* FROM products p WHERE ' . $where . ' ORDER BY ' . $order, $params);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function where(CatalogFilters $f): array
    {
        $sellable = $this->aggregates->sellableCondition();
        $where = ['p.is_active = 1'];
        $params = [];
        $in = static function (string $column, array $values, string $prefix) use (&$params): string {
            $placeholders = [];
            foreach (array_values($values) as $i => $value) {
                $placeholders[] = ':' . $prefix . $i;
                $params[$prefix . $i] = $value;
            }

            return $column . ' IN (' . implode(',', $placeholders) . ')';
        };

        if ($f->q !== '') {
            foreach (array_slice(preg_split('/\s+/', mb_strtolower($f->q)) ?: [], 0, 6) as $i => $token) {
                if ($token === '') {
                    continue;
                }
                $where[] = "p.search_text LIKE :q$i ESCAPE '\\'";
                $params['q' . $i] = '%' . addcslashes($token, '%_\\') . '%';
            }
        }
        if ($f->brands !== []) {
            $where[] = $in('p.brand', $f->brands, 'b');
        }
        if ($f->lines !== []) {
            $where[] = $in('p.line', $f->lines, 'l');
        }
        if ($f->genders !== []) {
            $where[] = $in('p.gender', $f->genders, 'g');
        }
        if ($f->sizeCategories !== []) {
            $where[] = $in('p.size_category', $f->sizeCategories, 'sc');
        }
        if ($f->category !== '') {
            $where[] = "p.category_key LIKE :cat ESCAPE '\\'";
            $params['cat'] = addcslashes(rtrim($f->category, '/') . '/', '%_\\') . '%';
        }
        if ($f->regime !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM offers o JOIN variants v ON v.id = o.variant_id WHERE v.product_id = p.id AND ' . $sellable . ' AND o.vat_regime = :regime)';
            $params['regime'] = $f->regime;
        }
        if ($f->sizes !== []) {
            $column = 'v.size_' . $f->sizeSystem;
            $where[] = 'EXISTS (SELECT 1 FROM offers o JOIN variants v ON v.id = o.variant_id WHERE v.product_id = p.id AND ' . $sellable . ' AND ' . $in($column, $f->sizes, 'sz') . ')';
        }
        if ($f->immediateOnly) {
            $where[] = 'p.has_immediate = 1';
        }
        if ($f->priceMinCents !== null) {
            $where[] = 'p.min_price_cents >= :pmin';
            $params['pmin'] = $f->priceMinCents;
        }
        if ($f->priceMaxCents !== null) {
            $where[] = 'p.min_price_cents <= :pmax';
            $params['pmax'] = $f->priceMaxCents;
        }
        $high = $this->settings->int('catalog.availability_high_min');
        $low = $this->settings->int('catalog.availability_low_max');
        if ($f->availability === 'alta') {
            $where[] = 'p.total_available >= :ah';
            $params['ah'] = $high;
        } elseif ($f->availability === 'bassa') {
            $where[] = 'p.total_available BETWEEN 1 AND :al';
            $params['al'] = $low;
        } elseif ($f->availability === 'media') {
            $where[] = 'p.total_available > :al AND p.total_available < :ah';
            $params['al'] = $low;
            $params['ah'] = $high;
        }
        if ($f->availableOnly) {
            $where[] = 'p.total_available > 0';
        }
        if ($f->featuredOnly) {
            $where[] = 'p.is_featured = 1';
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param array<string, mixed> $params */
    private function order(CatalogFilters $f, array &$params): string
    {
        // Gli esauriti restano in fondo alla griglia (docs/07).
        $soldOutLast = '(p.total_available = 0)';
        $order = match ($f->sort) {
            'nome' => 'p.name COLLATE NOCASE',
            'prezzo_asc' => 'p.min_price_cents IS NULL, p.min_price_cents, p.name COLLATE NOCASE',
            'prezzo_desc' => 'p.min_price_cents IS NULL, p.min_price_cents DESC, p.name COLLATE NOCASE',
            'disponibilita' => 'p.total_available DESC, p.name COLLATE NOCASE',
            'novita' => 'p.first_seen_at DESC, p.id DESC',
            default => 'p.is_featured DESC, p.has_immediate DESC, p.total_available DESC, p.name COLLATE NOCASE',
        };
        if ($f->sort === 'rilevanza' && $f->q !== '') {
            $params['qexact'] = mb_strtolower($f->q);
            $params['qprefix'] = addcslashes(mb_strtolower($f->q), '%_\\') . '%';
            $order = "(lower(p.sku) = :qexact) DESC, (lower(p.name) LIKE :qprefix ESCAPE '\\') DESC, " . $order;
        }

        return $soldOutLast . ', ' . $order . ', p.id';
    }

    /**
     * Opzioni dei filtri (sul catalogo attivo).
     *
     * @return array{brands: list<array{value: string, count: int}>, lines: list<array{value: string, count: int}>, genders: list<array{value: string, count: int}>, size_categories: list<array{value: string, count: int}>, categories: list<array{key: string, label: string, depth: int, indent: string, count: int}>, sizes: list<string>, regimes: list<string>}
     */
    public function facets(string $sizeSystem): array
    {
        $group = fn (string $column): array => array_map(
            static fn (array $r): array => ['value' => (string) $r['v'], 'count' => (int) $r['n']],
            $this->db->all("SELECT $column AS v, COUNT(*) AS n FROM products WHERE is_active = 1 AND $column != '' GROUP BY $column ORDER BY $column COLLATE NOCASE"),
        );
        $categories = [];
        foreach ($this->db->all('SELECT category_key, COUNT(*) AS n FROM products WHERE is_active = 1 AND category_key != \'\' GROUP BY category_key') as $row) {
            $segments = array_values(array_filter(explode('/', (string) $row['category_key']), static fn (string $s): bool => $s !== ''));
            $key = '';
            foreach ($segments as $depth => $segment) {
                $key .= $segment . '/';
                $categories[$key] ??= ['key' => rtrim($key, '/'), 'label' => $segment, 'depth' => $depth, 'indent' => str_repeat('— ', $depth), 'count' => 0];
                $categories[$key]['count'] += (int) $row['n'];
            }
        }
        ksort($categories, SORT_NATURAL | SORT_FLAG_CASE);
        $sellable = $this->aggregates->sellableCondition();
        $column = 'v.size_' . (in_array($sizeSystem, CatalogFilters::SIZE_SYSTEMS, true) ? $sizeSystem : 'eu');
        $sizes = $this->db->column(
            "SELECT $column FROM variants v JOIN offers o ON o.variant_id = v.id JOIN products p ON p.id = v.product_id
             WHERE p.is_active = 1 AND $sellable AND $column != '' GROUP BY $column ORDER BY MIN(v.size_sort), $column",
        );
        $regimes = $this->db->column("SELECT DISTINCT o.vat_regime FROM offers o JOIN variants v ON v.id = o.variant_id WHERE $sellable ORDER BY o.vat_regime");

        return [
            'brands' => $group('brand'),
            'lines' => $group('line'),
            'genders' => $group('gender'),
            'size_categories' => $group('size_category'),
            'categories' => array_values($categories),
            'sizes' => array_map('strval', $sizes),
            'regimes' => array_map('strval', $regimes),
        ];
    }
}
