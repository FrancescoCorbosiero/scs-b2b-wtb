<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

use App\Catalog\ProductSync;
use App\Catalog\StockSync;
use App\Settings\SettingsService;
use App\Supplier\Wtb\Http\TransportResponse;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class StockSyncTest extends TestCase
{
    public function testStockUpdatesZeroesAndImportsNewSkus(): void
    {
        $app = new TestApp();
        $app->get(ProductSync::class)->run();
        $result = $app->get(StockSync::class)->run();
        self::assertSame('ok', $result->status);
        self::assertSame(2, $result->counts['changed']);
        self::assertSame(1, $result->counts['zeroed'], 'ZX1010-010 assente dallo stock');
        self::assertSame(1, $result->counts['imported'], 'ZX1011-110 importato con /products/?sku=');

        self::assertSame(2, $app->db->int("SELECT o.available FROM offers o JOIN variants v ON v.id = o.variant_id WHERE v.wtb_variant_id = 90011 AND o.stock_type = 'immediate'"));
        self::assertSame(0, $app->db->int("SELECT total_available FROM products WHERE sku = 'ZX1010-010'"));
        self::assertSame(1, $app->db->int("SELECT COUNT(*) FROM products WHERE sku = 'ZX1011-110' AND total_available = 3"));
        self::assertNotNull($app->db->value("SELECT min_price_cents FROM products WHERE sku = 'ZX1011-110'"), 'Prezzi calcolati anche per i nuovi');
    }

    public function testUnchangedStockIsSkipped(): void
    {
        $app = new TestApp();
        $app->syncCatalog();
        self::assertSame('skipped', $app->get(StockSync::class)->run()->status);
        self::assertSame('ok', $app->get(StockSync::class)->run(true)->status);
    }

    public function testFailedTargetedImportIsOnlyAWarning(): void
    {
        $app = new TestApp();
        $app->get(ProductSync::class)->run();
        $app->fixtures()->queue('GET', '/products/', TransportResponse::json(401, ['detail' => 'no']));
        $result = $app->get(StockSync::class)->run();
        self::assertSame('ok', $result->status);
        self::assertSame(0, $result->counts['imported']);
        self::assertStringContainsString('Import mirato non riuscito', implode(' ', $result->warnings));
        self::assertSame(2, $result->counts['changed'], 'Gli altri aggiornamenti restano');
    }

    public function testSellableSettingsChangeAggregates(): void
    {
        $app = new TestApp();
        $app->syncCatalog();
        $sku = 'ZX1004-400';
        self::assertSame(35, $app->db->int('SELECT total_available FROM products WHERE sku = :s', ['s' => $sku]));
        $app->get(SettingsService::class)->setValue('catalog.sell_extended', false, 'test');
        self::assertSame(4, $app->db->int('SELECT total_available FROM products WHERE sku = :s', ['s' => $sku]), 'Solo pronta consegna');
        $app->get(SettingsService::class)->setValue('catalog.regimes', 'ordinary', 'test');
        self::assertSame(0, $app->db->int('SELECT total_available FROM products WHERE sku = :s', ['s' => $sku]), 'Regime margine spento');
        self::assertSame(7, $app->db->int("SELECT total_available FROM products WHERE sku = 'ZX1005-500'"));
    }

    public function testDefaultMarginChangeReprices(): void
    {
        $app = new TestApp();
        $app->syncCatalog();
        $app->get(SettingsService::class)->setFromInput('prices.default_margin_value', '10', 'test');
        // 10% su 71,37 = 78,507 → 79,00
        self::assertSame(7900, $app->db->int("SELECT o.sale_price_cents FROM offers o JOIN variants v ON v.id = o.variant_id WHERE v.wtb_variant_id = 90011 AND o.stock_type = 'immediate'"));
    }
}
