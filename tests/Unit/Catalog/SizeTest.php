<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Catalog\SizeCategory;
use App\Catalog\SizeSort;
use PHPUnit\Framework\TestCase;

final class SizeTest extends TestCase
{
    public function testSizeCategory(): void
    {
        self::assertSame('adult', SizeCategory::classify('unisex', 'Shoe (GS)', ['5.5Y']), 'Solo i kids hanno GS/PS');
        self::assertSame('gs', SizeCategory::classify('kids', 'Air Force 1 (GS)', ['4', '5']), 'La sigla nel nome vince');
        self::assertSame('ps', SizeCategory::classify('kids', 'Air Max (PS)', ['11C']));
        self::assertSame('ps', SizeCategory::classify('kids', 'Jordan 1 (TD)', ['7C']));
        self::assertSame('gs', SizeCategory::classify('kids', 'Senza sigla', ['4Y', '5Y']));
        self::assertSame('ps', SizeCategory::classify('kids', 'Senza sigla', ['10.5C', '11C', '12C']));
        self::assertSame('gs', SizeCategory::classify('kids', 'Senza sigla', ['4', '']), 'Nessun segnale: GS');
    }

    public function testSizeSortHandlesFractions(): void
    {
        $sizes = ['38 2/3', '36', '37 1/3', '36.5', '45', 'XL', '38'];
        usort($sizes, static fn (string $a, string $b): int => SizeSort::key($a) <=> SizeSort::key($b));
        self::assertSame(['36', '36.5', '37 1/3', '38', '38 2/3', '45', 'XL'], $sizes);
        self::assertSame(36666, SizeSort::key('36 2/3'));
    }
}
