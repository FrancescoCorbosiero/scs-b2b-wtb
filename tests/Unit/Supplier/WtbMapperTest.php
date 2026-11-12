<?php

declare(strict_types=1);

namespace Tests\Unit\Supplier;

use App\Bootstrap;
use App\Supplier\Exception\SupplierFormatException;
use App\Supplier\Wtb\WtbMapper;
use PHPUnit\Framework\TestCase;

final class WtbMapperTest extends TestCase
{
    /** @return array<mixed> */
    private static function fixture(string $file): array
    {
        return json_decode((string) file_get_contents(Bootstrap::root() . '/fixtures/wtb/' . $file), true);
    }

    public function testProductsPageFromFixtures(): void
    {
        $mapper = new WtbMapper();
        $page = $mapper->productsPage(self::fixture('products-page-1.json'));
        self::assertTrue($page['has_more']);
        self::assertCount(6, $page['products']);
        $p = $page['products'][0];
        self::assertSame('ZX1001-100', $p->sku);
        self::assertSame('kids', $p->gender);
        self::assertSame(['Sneakers', 'Nike', 'Air Force 1', 'Bambino'], $p->categoryPath);
        self::assertSame('4Y', $p->variants[0]->sizeUs);
        self::assertSame(7137, $p->variants[0]->offers[0]->costCents, 'Prezzo da stringa a centesimi');
        self::assertSame('immediate', $p->variants[0]->offers[0]->stockType);
        $samba = $page['products'][4];
        self::assertSame('36 2/3', $samba->variants[0]->sizeEu);
        self::assertSame('', $samba->variants[2]->sizeUs, 'Taglie US vuote restano stringhe vuote');
        self::assertSame([], $mapper->warnings());
    }

    public function testBadRowsAreSkippedWithWarnings(): void
    {
        $mapper = new WtbMapper();
        $page = self::fixture('products-page-1.json');
        $page['results'][0]['sizes'][0]['offers'][0]['vat_regime'] = 'reduced';
        $page['results'][1]['sku'] = '';
        $page['results'][2]['sizes'][0]['offers'][0]['price'] = 'gratis';
        $page['results'][3]['gender'] = 'alien';
        $result = $mapper->productsPage($page);
        self::assertCount(5, $result['products'], 'Il prodotto senza SKU è scartato, gli altri no');
        self::assertCount(1, $result['products'][0]->variants[0]->offers, 'Offerta con regime sconosciuto scartata');
        self::assertSame('', $result['products'][2]->gender);
        self::assertCount(4, $mapper->warnings());
    }

    public function testStructuralErrorsStopEverything(): void
    {
        $mapper = new WtbMapper();
        foreach ([
            ['currency' => 'USD', 'results' => [], 'has_more' => false],
            ['currency' => 'EUR', 'has_more' => false],
            ['currency' => 'EUR', 'results' => []],
        ] as $broken) {
            try {
                $mapper->productsPage($broken);
                self::fail('Pagina rotta accettata');
            } catch (SupplierFormatException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testStockShippingQuoteAndOrder(): void
    {
        $mapper = new WtbMapper();
        $rows = $mapper->stock(self::fixture('stock.json'));
        self::assertCount(50, $rows);

        $profile = $mapper->shipping(self::fixture('shipping.json'));
        self::assertSame('retail', $profile->profile);
        self::assertSame('Zona Italia', $profile->zoneFor('it')?->name);
        self::assertTrue($profile->zoneFor('GB')?->dutiesPaidByRecipient);
        self::assertSame(930 + 3 * 240, $profile->zoneFor('IT')?->costFor(4));
        self::assertSame(840, $profile->extendedTransportFor(1));
        self::assertSame(1390, $profile->extendedTransportFor(5));
        self::assertSame(0, $profile->extendedTransportFor(6), 'Gratis dalla soglia');
        self::assertNull($profile->minOrderPieces);

        $quote = $mapper->quote(self::fixture('quote-extended-only.json'));
        self::assertSame(0, $quote->shippingCents, '"shipping": "0" senza decimali');
        self::assertSame(1170, $quote->extendedTransportCents);
        self::assertSame('extended', $quote->lines[0]->stockType);

        $test = $mapper->order(self::fixture('order-test.json'));
        self::assertTrue($test->isTest);
        self::assertNull($test->id);
        self::assertNull($test->paymentInstructions);

        $detail = $mapper->order(self::fixture('order-detail.json'));
        self::assertSame('shipped', $detail->status);
        self::assertSame('BRT', $detail->tracking[0]['carrier']);
        self::assertSame(41488, $detail->totalCents);
    }

    public function testMoneyParsing(): void
    {
        self::assertSame(6384, WtbMapper::money('63.84'));
        self::assertSame(0, WtbMapper::money('0'));
        self::assertSame(1250, WtbMapper::money('12.5'));
        self::assertSame(1200, WtbMapper::money(12));
        self::assertNull(WtbMapper::money('12,50'));
        self::assertNull(WtbMapper::money(null));
        self::assertNull(WtbMapper::money('-3.00'));
    }
}
