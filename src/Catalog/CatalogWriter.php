<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Supplier\Model\SupplierProduct;
use App\Support\Db;

/**
 * Scritture del catalogo a partire dai modelli del fornitore (dentro le transazioni dei sync).
 * Non cancella mai prodotti o varianti: li disattiva (gli ordini passati li citano).
 */
final class CatalogWriter
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array{id: int, created: bool} */
    public function upsertProduct(SupplierProduct $p, string $now): array
    {
        $existing = $this->db->one('SELECT id FROM products WHERE sku = :sku', ['sku' => $p->sku]);
        $usSizes = array_map(static fn ($v): string => $v->sizeUs, $p->variants);
        $eans = array_filter(array_map(static fn ($v): string => $v->ean, $p->variants));
        $data = [
            'name' => $p->name,
            'brand' => $p->brand,
            'category' => $p->category,
            'line' => $p->line,
            'gender' => $p->gender,
            'category_path' => (string) json_encode($p->categoryPath, JSON_UNESCAPED_UNICODE),
            'category_key' => self::categoryKey($p->categoryPath),
            'description' => $p->description,
            'size_category' => SizeCategory::classify($p->gender, $p->name, $usSizes),
            'search_text' => mb_strtolower(implode(' ', array_merge([$p->sku, $p->name, $p->brand, $p->line], $eans))),
            'is_active' => 1,
            'in_feed' => 1,
            'last_seen_at' => $now,
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $id = $this->db->insert('products', $data + ['sku' => $p->sku, 'first_seen_at' => $now]);
            $created = true;
        } else {
            $id = (int) $existing['id'];
            $this->db->update('products', $data, 'id = :id', ['id' => $id]);
            $created = false;
        }
        $this->syncImages($id, $p->images);
        $this->syncVariants($id, $p, $now);

        return ['id' => $id, 'created' => $created];
    }

    /** @param list<string> $path */
    public static function categoryKey(array $path): string
    {
        $segments = array_map(static fn (string $s): string => str_replace('/', '-', $s), $path);

        return $segments === [] ? '' : implode('/', $segments) . '/';
    }

    /** @param list<string> $urls */
    private function syncImages(int $productId, array $urls): void
    {
        $current = [];
        foreach ($this->db->all('SELECT position, source_url FROM product_images WHERE product_id = :p', ['p' => $productId]) as $row) {
            $current[(int) $row['position']] = (string) $row['source_url'];
        }
        foreach ($urls as $position => $url) {
            if (!isset($current[$position])) {
                $this->db->insert('product_images', ['product_id' => $productId, 'position' => $position, 'source_url' => $url, 'status' => 'pending']);
            } elseif ($current[$position] !== $url) {
                // Immagine cambiata: la cache si invalida da sola (l'URL pubblico contiene l'impronta della sorgente).
                $this->db->run(
                    "UPDATE product_images SET source_url = :u, status = 'pending', cached_at = NULL, error = '' WHERE product_id = :p AND position = :pos",
                    ['u' => $url, 'p' => $productId, 'pos' => $position],
                );
            }
        }
        $this->db->run('DELETE FROM product_images WHERE product_id = :p AND position >= :n', ['p' => $productId, 'n' => count($urls)]);
    }

    private function syncVariants(int $productId, SupplierProduct $p, string $now): void
    {
        $seen = [];
        foreach ($p->variants as $variant) {
            $row = $this->db->one('SELECT id FROM variants WHERE wtb_variant_id = :w', ['w' => $variant->variantId]);
            $data = [
                'product_id' => $productId,
                'size_eu' => $variant->sizeEu,
                'size_us' => $variant->sizeUs,
                'size_uk' => $variant->sizeUk,
                'size_sort' => SizeSort::key($variant->sizeEu),
                'ean' => $variant->ean,
                'is_active' => 1,
            ];
            if ($row === null) {
                $variantId = $this->db->insert('variants', $data + ['wtb_variant_id' => $variant->variantId]);
            } else {
                $variantId = (int) $row['id'];
                $this->db->update('variants', $data, 'id = :id', ['id' => $variantId]);
            }
            $seen[] = $variantId;
            $this->syncOffers($variantId, $variant->offers, $now);
        }
        // Varianti sparite da un prodotto presente: inattive, offerte a 0.
        $params = ['p' => $productId, 'now' => $now];
        $notIn = '';
        if ($seen !== []) {
            $placeholders = [];
            foreach ($seen as $i => $id) {
                $placeholders[] = ':v' . $i;
                $params['v' . $i] = $id;
            }
            $notIn = ' AND id NOT IN (' . implode(',', $placeholders) . ')';
        }
        $this->db->run(
            'UPDATE offers SET available = 0, updated_at = :now WHERE available > 0 AND variant_id IN (SELECT id FROM variants WHERE product_id = :p' . $notIn . ')',
            $params,
        );
        unset($params['now']);
        $this->db->run('UPDATE variants SET is_active = 0 WHERE product_id = :p' . $notIn, $params);
    }

    /** @param list<\App\Supplier\Model\SupplierOffer> $offers */
    public function syncOffers(int $variantId, array $offers, string $now): void
    {
        $present = [];
        foreach ($offers as $offer) {
            $present[$offer->regime . '|' . $offer->stockType] = true;
            $this->upsertOffer($variantId, $offer->regime, $offer->stockType, $offer->available, $offer->costCents, $offer->shipsInDays, $now);
        }
        foreach ($this->db->all('SELECT id, vat_regime, stock_type FROM offers WHERE variant_id = :v AND available > 0', ['v' => $variantId]) as $row) {
            if (!isset($present[$row['vat_regime'] . '|' . $row['stock_type']])) {
                $this->db->run('UPDATE offers SET available = 0, updated_at = :now WHERE id = :id', ['now' => $now, 'id' => $row['id']]);
            }
        }
    }

    /** @return bool true se è cambiato qualcosa */
    public function upsertOffer(int $variantId, string $regime, string $stockType, int $available, int $costCents, string $shipsInDays, string $now): bool
    {
        $row = $this->db->one(
            'SELECT id, available, cost_cents, ships_in_days FROM offers WHERE variant_id = :v AND vat_regime = :r AND stock_type = :s',
            ['v' => $variantId, 'r' => $regime, 's' => $stockType],
        );
        if ($row === null) {
            $this->db->insert('offers', [
                'variant_id' => $variantId, 'vat_regime' => $regime, 'stock_type' => $stockType, 'available' => $available,
                'cost_cents' => $costCents, 'ships_in_days' => $shipsInDays, 'updated_at' => $now,
            ]);

            return true;
        }
        if ((int) $row['available'] === $available && (int) $row['cost_cents'] === $costCents && (string) $row['ships_in_days'] === $shipsInDays) {
            return false;
        }
        $this->db->run(
            'UPDATE offers SET available = :a, cost_cents = :c, ships_in_days = :s, updated_at = :now WHERE id = :id',
            ['a' => $available, 'c' => $costCents, 's' => $shipsInDays, 'now' => $now, 'id' => $row['id']],
        );

        return true;
    }
}
