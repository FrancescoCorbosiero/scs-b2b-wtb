<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Pricing\RepriceService;
use App\Supplier\Exception\SupplierException;
use App\Supplier\SupplierCatalog;
use App\Support\Clock;
use App\Support\Db;
use App\Support\SystemFlags;

/**
 * Sync rapido di disponibilità, costi e tempi (docs/03 § Sync rapido). Varianti sconosciute ⇒ import
 * mirato con /products/?sku=; se fallisce, ci pensa il prossimo sync completo.
 */
final class StockSync
{
    public const FLAG_HASH = 'stock.hash';

    public function __construct(
        private readonly SupplierCatalog $catalog,
        private readonly CatalogWriter $writer,
        private readonly RepriceService $reprice,
        private readonly SystemFlags $flags,
        private readonly Db $db,
        private readonly Clock $clock,
    ) {
    }

    public function run(bool $force = false): SyncResult
    {
        $feed = $this->catalog->stock();
        $warnings = $this->catalog->warnings();
        if (!$force && $feed->hash === $this->flags->get(self::FLAG_HASH)) {
            return new SyncResult('skipped', ['rows' => count($feed->rows)], 'Stock invariato dal giro precedente.', $warnings);
        }

        $variants = [];
        foreach ($this->db->all('SELECT id, wtb_variant_id, product_id FROM variants') as $row) {
            $variants[(int) $row['wtb_variant_id']] = ['id' => (int) $row['id'], 'product_id' => (int) $row['product_id']];
        }
        $now = $this->clock->iso();
        $unknown = [];
        $changedProducts = [];
        $counts = ['rows' => count($feed->rows), 'changed' => 0, 'zeroed' => 0, 'unknown' => 0, 'imported' => 0];

        $this->db->transaction(function (Db $db) use ($feed, $variants, $now, &$unknown, &$changedProducts, &$counts): void {
            $present = [];
            foreach ($feed->rows as $row) {
                $variant = $variants[$row->variantId] ?? null;
                if ($variant === null) {
                    $unknown[$row->sku] = true;
                    continue;
                }
                $present[$variant['id'] . '|' . $row->regime . '|' . $row->stockType] = true;
                if ($this->writer->upsertOffer($variant['id'], $row->regime, $row->stockType, $row->available, $row->costCents, $row->shipsInDays, $now)) {
                    $counts['changed']++;
                    $changedProducts[$variant['product_id']] = true;
                }
            }
            // Payload completo (M0): un'offerta assente vale 0.
            if ($feed->complete) {
                $rows = $db->all('SELECT o.id, o.variant_id, o.vat_regime, o.stock_type, v.product_id FROM offers o JOIN variants v ON v.id = o.variant_id WHERE o.available > 0');
                foreach ($rows as $offer) {
                    if (!isset($present[$offer['variant_id'] . '|' . $offer['vat_regime'] . '|' . $offer['stock_type']])) {
                        $db->run('UPDATE offers SET available = 0, updated_at = :now WHERE id = :id', ['now' => $now, 'id' => $offer['id']]);
                        $counts['zeroed']++;
                        $changedProducts[(int) $offer['product_id']] = true;
                    }
                }
            }
            // Prodotti tornati disponibili: di nuovo visibili.
            foreach (array_keys($changedProducts) as $productId) {
                $db->run(
                    'UPDATE products SET is_active = 1, last_seen_at = :now WHERE id = :id AND EXISTS (
                        SELECT 1 FROM offers o JOIN variants v ON v.id = o.variant_id WHERE v.product_id = products.id AND o.available > 0)',
                    ['now' => $now, 'id' => $productId],
                );
            }
        });

        // Import mirato degli SKU nuovi.
        $counts['unknown'] = count($unknown);
        if ($unknown !== []) {
            try {
                $products = $this->catalog->productsBySku(array_keys($unknown));
                $warnings = array_merge($warnings, $this->catalog->warnings());
                $this->db->transaction(function () use ($products, $now, &$changedProducts, &$counts): void {
                    foreach ($products as $product) {
                        $result = $this->writer->upsertProduct($product, $now);
                        $changedProducts[$result['id']] = true;
                        $counts['imported']++;
                    }
                });
            } catch (SupplierException $e) {
                $warnings[] = 'Import mirato non riuscito (' . $e->getMessage() . '): ci pensa il prossimo sync completo.';
            }
        }

        $this->reprice->repriceProducts(array_map('intval', array_keys($changedProducts)));
        $this->flags->set(self::FLAG_HASH, $feed->hash);

        return new SyncResult('ok', $counts + ['warnings' => count($warnings)], sprintf(
            '%d righe: %d offerte cambiate, %d azzerate, %d prodotti nuovi importati.',
            $counts['rows'],
            $counts['changed'],
            $counts['zeroed'],
            $counts['imported'],
        ), $warnings);
    }
}
