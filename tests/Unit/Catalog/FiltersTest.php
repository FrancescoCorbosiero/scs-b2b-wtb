<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Catalog\CatalogFilters;
use App\Shipping\DeliveryEstimate;
use PHPUnit\Framework\TestCase;

final class FiltersTest extends TestCase
{
    public function testParsingAndRoundTrip(): void
    {
        $f = CatalogFilters::fromQuery([
            'q' => ' kayano ', 'brand' => ['ASICS', 'Nike', ''], 'genere' => ['kids', 'aliens'], 'eta' => ['gs'],
            'taglie' => ['36 2/3'], 'regime' => 'ordinary', 'pronta' => '1', 'prezzo_min' => '50', 'prezzo_max' => '120,5',
            'disp' => 'alta', 'disponibili' => '1', 'ordina' => 'prezzo_desc', 'page' => '3', 'sistema' => 'us',
        ], true);
        self::assertSame('kayano', $f->q);
        self::assertSame(['ASICS', 'Nike'], $f->brands);
        self::assertSame(['kids'], $f->genders);
        self::assertSame(5000, $f->priceMinCents);
        self::assertSame(12050, $f->priceMaxCents);
        self::assertSame('prezzo_desc', $f->sort);
        self::assertSame(3, $f->page);
        self::assertSame('us', $f->sizeSystem);
        self::assertTrue($f->isFiltered());
        $again = CatalogFilters::fromQuery($f->toQuery(), true);
        self::assertSame($f->brands, $again->brands);
        self::assertSame('prezzo_desc', $again->sort);
    }

    public function testShowcaseIgnoresPriceFiltersAndSorts(): void
    {
        $f = CatalogFilters::fromQuery(['prezzo_min' => '50', 'prezzo_max' => '60', 'ordina' => 'prezzo_asc'], false);
        self::assertNull($f->priceMinCents);
        self::assertNull($f->priceMaxCents);
        self::assertSame('rilevanza', $f->sort);
        self::assertFalse($f->isFiltered());
    }

    public function testDeliveryEstimate(): void
    {
        self::assertSame([1, 2], DeliveryEstimate::parseRange('1-2'));
        self::assertSame([3, 3], DeliveryEstimate::parseRange('3'));
        self::assertNull(DeliveryEstimate::parseRange('presto'));
        self::assertSame([3, 5], DeliveryEstimate::departure(['1-2', '3-5']), 'Ordine misto: vale la partenza più lunga');
        self::assertSame([4, 9], DeliveryEstimate::delivery([3, 5], 1, 4));
    }
}
