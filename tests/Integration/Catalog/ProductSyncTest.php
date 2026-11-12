<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

use App\Catalog\ProductSync;
use App\Catalog\SyncBlockedException;
use App\Settings\SettingsService;
use App\Supplier\Exception\SupplierUnavailableException;
use App\Supplier\Wtb\Http\TransportResponse;
use App\Support\SystemFlags;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class ProductSyncTest extends TestCase
{
    /** @return array<string, mixed> */
    private function product(TestApp $app, string $sku): array
    {
        $row = $app->db->one('SELECT * FROM products WHERE sku = :s', ['s' => $sku]);
        self::assertNotNull($row, $sku);

        return $row;
    }

    public function testFixtureSyncPopulatesTheCatalog(): void
    {
        $app = new TestApp();
        $result = $app->get(ProductSync::class)->run();
        self::assertSame('ok', $result->status);
        self::assertSame(10, $app->db->int('SELECT COUNT(*) FROM products'));
        self::assertSame(39, $app->db->int('SELECT COUNT(*) FROM variants'));
        self::assertSame(50, $app->db->int('SELECT COUNT(*) FROM offers'));
        self::assertSame(1, $app->db->int("SELECT COUNT(*) FROM wtb_snapshots WHERE kind = 'shipping'"));

        self::assertSame('gs', $this->product($app, 'ZX1001-100')['size_category'], 'GS da suffisso Y e sigla');
        self::assertSame('gs', $this->product($app, 'ZX1002-200')['size_category'], 'GS solo dalla sigla nel nome');
        self::assertSame('ps', $this->product($app, 'ZX1003-300')['size_category']);
        self::assertSame('adult', $this->product($app, 'ZX1006-600')['size_category']);

        // Margine di default 5%, arrotondamento all'euro per eccesso: 71,37 → 74,94 → 75,00.
        $offer = $app->db->one("SELECT o.* FROM offers o JOIN variants v ON v.id = o.variant_id WHERE v.wtb_variant_id = 90011 AND o.stock_type = 'immediate'");
        self::assertSame(7137, $offer['cost_cents']);
        self::assertSame(7500, $offer['sale_price_cents']);

        $p = $this->product($app, 'ZX1001-100');
        self::assertSame(21, $p['total_available']);
        self::assertSame(7500, $p['min_price_cents']);
        self::assertSame(1, $p['has_immediate']);
        self::assertStringContainsString('air force', $p['search_text']);
        $samba = $this->product($app, 'ZX1005-500');
        self::assertSame('ordinary', $samba['min_price_regime']);
        self::assertSame(['36 2/3', '37 1/3', '38', '38 2/3'], $app->db->column('SELECT size_eu FROM variants WHERE product_id = :p ORDER BY size_sort', ['p' => $samba['id']]));
    }

    public function testSecondRunIsIdempotent(): void
    {
        $app = new TestApp();
        $app->get(ProductSync::class)->run();
        $before = $app->db->all('SELECT p.id, p.sku, v.id AS vid, o.id AS oid, o.available, o.sale_price_cents FROM products p JOIN variants v ON v.product_id = p.id JOIN offers o ON o.variant_id = v.id ORDER BY o.id');
        $app->clock->advance(3600);
        $result = $app->get(ProductSync::class)->run();
        self::assertSame(0, $result->counts['created']);
        self::assertSame(10, $result->counts['updated']);
        $after = $app->db->all('SELECT p.id, p.sku, v.id AS vid, o.id AS oid, o.available, o.sale_price_cents FROM products p JOIN variants v ON v.product_id = p.id JOIN offers o ON o.variant_id = v.id ORDER BY o.id');
        self::assertSame($before, $after);
    }

    public function testAnErrorHalfWayWritesNothing(): void
    {
        $app = new TestApp();
        $app->get(ProductSync::class)->run();
        $app->db->run("UPDATE offers SET available = 77 WHERE id = 1");
        $app->fixtures()->queue('GET', '/products/', TransportResponse::json(200, json_decode((string) file_get_contents(\App\Bootstrap::root() . '/fixtures/wtb/products-page-1.json'), true)));
        $app->fixtures()->queue('GET', '/products/', TransportResponse::json(500, []));
        $app->fixtures()->queue('GET', '/products/', TransportResponse::json(500, []));
        $app->fixtures()->queue('GET', '/products/', TransportResponse::json(500, []));
        try {
            $app->get(ProductSync::class)->run();
            self::fail('Nessuna eccezione');
        } catch (SupplierUnavailableException) {
            self::assertSame(77, $app->db->int('SELECT available FROM offers WHERE id = 1'), 'Nessuna scrittura parziale');
            self::assertSame(10, $app->db->int('SELECT COUNT(*) FROM products WHERE in_feed = 1'));
        }
    }

    public function testDropThresholdBlocksAndCanBeForced(): void
    {
        $app = new TestApp();
        $app->get(ProductSync::class)->run();
        $page = json_decode((string) file_get_contents(\App\Bootstrap::root() . '/fixtures/wtb/products-page-1.json'), true);
        $page['results'] = array_slice($page['results'], 0, 3);
        $page['has_more'] = false;
        $app->fixtures()->queue('GET', '/products/', TransportResponse::json(200, $page));
        try {
            $app->get(ProductSync::class)->run();
            self::fail('Il calo da 10 a 3 doveva bloccare');
        } catch (SyncBlockedException) {
            self::assertNotNull($app->get(SystemFlags::class)->getJson(ProductSync::FLAG_BLOCKED));
            self::assertSame(10, $app->db->int('SELECT COUNT(*) FROM products WHERE in_feed = 1'));
        }
        $app->fixtures()->queue('GET', '/products/', TransportResponse::json(200, $page));
        $result = $app->get(ProductSync::class)->run(true);
        self::assertSame(7, $result->counts['gone']);
        self::assertNull($app->get(SystemFlags::class)->get(ProductSync::FLAG_BLOCKED), 'L\'avviso sparisce dopo un sync riuscito');
    }

    public function testProductsGoneFromTheFeedStayVisibleAsSoldOutThenLeave(): void
    {
        $app = new TestApp();
        $app->get(ProductSync::class)->run();
        $page1 = json_decode((string) file_get_contents(\App\Bootstrap::root() . '/fixtures/wtb/products-page-1.json'), true);
        $page2 = json_decode((string) file_get_contents(\App\Bootstrap::root() . '/fixtures/wtb/products-page-2.json'), true);
        $page2['results'] = array_values(array_filter($page2['results'], static fn (array $p): bool => $p['sku'] !== 'ZX1010-010'));

        $app->clock->advance(86400);
        $app->fixtures()->queue('GET', '/products/', TransportResponse::json(200, $page1));
        $app->fixtures()->queue('GET', '/products/', TransportResponse::json(200, $page2));
        $app->get(ProductSync::class)->run();
        $gone = $this->product($app, 'ZX1010-010');
        self::assertSame(0, $gone['in_feed']);
        self::assertSame(1, $gone['is_active'], 'Esaurito ma ancora visibile (D-017)');
        self::assertSame(0, $gone['total_available']);
        self::assertNull($gone['min_price_cents']);

        $app->clock->advance(31 * 86400);
        $app->fixtures()->queue('GET', '/products/', TransportResponse::json(200, $page1));
        $app->fixtures()->queue('GET', '/products/', TransportResponse::json(200, $page2));
        $result = $app->get(ProductSync::class)->run();
        self::assertSame(1, $result->counts['deactivated']);
        self::assertSame(0, $this->product($app, 'ZX1010-010')['is_active'], 'Dopo 30 giorni esce dal catalogo');
        self::assertSame(1, $app->db->int("SELECT COUNT(*) FROM products WHERE sku = 'ZX1010-010'"), 'Mai cancellato');
    }

    public function testSoldOutDaysZeroDeactivatesImmediately(): void
    {
        $app = new TestApp();
        $app->get(ProductSync::class)->run();
        $app->get(SettingsService::class)->setValue('catalog.sold_out_days', 0, 'test');
        $page1 = json_decode((string) file_get_contents(\App\Bootstrap::root() . '/fixtures/wtb/products-page-1.json'), true);
        $page1['has_more'] = false;
        $app->fixtures()->queue('GET', '/products/', TransportResponse::json(200, $page1));
        $app->clock->advance(60);
        $app->get(ProductSync::class)->run(true);
        self::assertSame(4, $app->db->int('SELECT COUNT(*) FROM products WHERE is_active = 0'));
    }
}
