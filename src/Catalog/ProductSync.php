<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Pricing\RepriceService;
use App\Settings\SettingsService;
use App\Supplier\SupplierCatalog;
use App\Supplier\Wtb\WtbConfig;
use App\Support\Clock;
use App\Support\Db;
use App\Support\SystemFlags;

/**
 * Sync completo del catalogo (docs/03 § Sync completo): tutto o niente.
 * Si scarica e valida tutto PRIMA di aprire la transazione; un errore non scrive nulla.
 */
final class ProductSync
{
    public const FLAG_BLOCKED = 'catalog.sync_blocked';
    public const FLAG_PROFILE = 'supplier.profile';

    public function __construct(
        private readonly SupplierCatalog $catalog,
        private readonly CatalogWriter $writer,
        private readonly RepriceService $reprice,
        private readonly SettingsService $settings,
        private readonly SystemFlags $flags,
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly WtbConfig $config,
    ) {
    }

    /** @throws SyncBlockedException|\App\Supplier\Exception\SupplierException */
    public function run(bool $force = false): SyncResult
    {
        // 1-2. Profilo e catalogo completo, validati in memoria.
        $profile = $this->catalog->shippingProfile();
        $products = $this->catalog->allProducts();
        $warnings = $this->catalog->warnings();

        // 3. Soglia di calo: un feed rotto non svuota il catalogo.
        $previous = $this->db->int('SELECT COUNT(*) FROM products WHERE in_feed = 1');
        $now = count($products);
        $maxDrop = $this->config->maxDropPercent;
        if (!$force && $previous > 0 && $now * 100 < $previous * (100 - $maxDrop)) {
            $this->flags->setJson(self::FLAG_BLOCKED, ['previous' => $previous, 'now' => $now, 'max_drop_percent' => $maxDrop]);
            throw new SyncBlockedException(sprintf(
                'Sync bloccato: il feed passerebbe da %d a %d prodotti (calo oltre il %d%%). Controlla e, se è giusto, forza dal pannello sync.',
                $previous,
                $now,
                $maxDrop,
            ));
        }

        // 4. Una transazione per tutte le scritture.
        $counts = ['products' => $now, 'created' => 0, 'updated' => 0, 'gone' => 0, 'deactivated' => 0, 'variants' => 0, 'offers' => 0];
        $isoNow = $this->clock->iso();
        $this->db->transaction(function (Db $db) use ($profile, $products, $isoNow, &$counts): void {
            $db->insert('wtb_snapshots', [
                'kind' => 'shipping',
                'payload' => (string) json_encode($profile->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'fetched_at' => $isoNow,
            ]);
            $db->pdo()->exec('CREATE TEMP TABLE IF NOT EXISTS sync_seen (id INTEGER PRIMARY KEY)');
            $db->pdo()->exec('DELETE FROM sync_seen');
            foreach ($products as $product) {
                $result = $this->writer->upsertProduct($product, $isoNow);
                $db->run('INSERT OR IGNORE INTO sync_seen (id) VALUES (:id)', ['id' => $result['id']]);
                $counts[$result['created'] ? 'created' : 'updated']++;
                $counts['variants'] += count($product->variants);
                foreach ($product->variants as $variant) {
                    $counts['offers'] += count($variant->offers);
                }
            }
            // Spariti dal feed (M0: anche gli esauriti): offerte a 0, restano visibili come esauriti (D-017).
            $counts['gone'] = $db->run(
                'UPDATE products SET in_feed = 0, updated_at = :now WHERE in_feed = 1 AND id NOT IN (SELECT id FROM sync_seen)',
                ['now' => $isoNow],
            )->rowCount();
            $db->pdo()->exec('DELETE FROM sync_seen');
            $db->run(
                'UPDATE offers SET available = 0, updated_at = :now WHERE available > 0 AND variant_id IN (
                    SELECT v.id FROM variants v JOIN products p ON p.id = v.product_id WHERE p.in_feed = 0)',
                ['now' => $isoNow],
            );
            $days = $this->settings->int('catalog.sold_out_days');
            $cutoff = $days === 0 ? $isoNow : Clock::toIso($this->clock->now()->modify('-' . $days . ' days'));
            $counts['deactivated'] = $db->run(
                'UPDATE products SET is_active = 0, updated_at = :now WHERE in_feed = 0 AND is_active = 1 AND last_seen_at <= :cutoff',
                ['now' => $isoNow, 'cutoff' => $cutoff],
            )->rowCount();
            $db->run('DELETE FROM wtb_snapshots WHERE kind = :k AND id NOT IN (SELECT id FROM wtb_snapshots WHERE kind = :k ORDER BY id DESC LIMIT 30)', ['k' => 'shipping']);
        });
        $this->flags->clear(self::FLAG_BLOCKED);
        $this->flags->setJson(self::FLAG_PROFILE, ['profile' => $profile->profile, 'min_order' => $profile->minOrderPieces]);

        // 5. Prezzi di vendita e aggregati.
        $this->reprice->repriceAll();

        return new SyncResult('ok', $counts + ['warnings' => count($warnings)], sprintf(
            '%d prodotti nel feed (%d nuovi, %d spariti, %d usciti dal catalogo).',
            $now,
            $counts['created'],
            $counts['gone'],
            $counts['deactivated'],
        ), $warnings);
    }
}
