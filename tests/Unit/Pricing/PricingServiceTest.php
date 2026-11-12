<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Pricing\MarginSpec;
use App\Pricing\PricingService;
use App\Pricing\Rounding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PricingServiceTest extends TestCase
{
    /** @return iterable<array{int, string, int, string, int}> costo, tipo, valore, arrotondamento, atteso */
    public static function table(): iterable
    {
        // 5% su 71,37 = 74,9385
        yield [7137, 'percent', 500, 'whole', 7500];
        yield [7137, 'percent', 500, 'half', 7500];
        yield [7137, 'percent', 500, 'none', 7494];
        // 5% su 100,00 = 105,00 esatto: nessun arrotondamento in più
        yield [10000, 'percent', 500, 'whole', 10500];
        // 7,5% su 79,67 = 85,64525
        yield [7967, 'percent', 750, 'whole', 8600];
        yield [7967, 'percent', 750, 'half', 8600];
        yield [7967, 'percent', 750, 'none', 8565];
        // fisso +10 €
        yield [11843, 'fixed', 1000, 'whole', 12900];
        yield [11843, 'fixed', 1000, 'half', 12850];
        yield [11843, 'fixed', 1000, 'none', 12843];
        // prezzo fisso: ignora costo e arrotondamento (anche sotto costo)
        yield [26841, 'fixed_price', 19999, 'whole', 19999];
        // margine zero: solo arrotondamento per eccesso
        yield [5213, 'percent', 0, 'whole', 5300];
        yield [5200, 'percent', 0, 'whole', 5200];
    }

    #[DataProvider('table')]
    public function testSalePrice(int $cost, string $type, int $value, string $rounding, int $expected): void
    {
        self::assertSame($expected, PricingService::salePrice($cost, new MarginSpec($type, $value), $rounding));
    }

    public function testDefaultSpecFromSettings(): void
    {
        $spec = MarginSpec::fromDefault('percent', '5.00');
        self::assertSame(['percent', 500], [$spec->type, $spec->value]);
        $spec = MarginSpec::fromDefault('percent', '7.50');
        self::assertSame(750, $spec->value);
        $spec = MarginSpec::fromDefault('fixed', '10.00');
        self::assertSame(['fixed', 1000], [$spec->type, $spec->value]);
    }

    public function testPercentHalfUp(): void
    {
        self::assertSame(2200, Rounding::percentHalfUp(10000, 2200));
        self::assertSame(2301, Rounding::percentHalfUp(10457, 2200), '22% di 104,57 = 23,0054');
        self::assertSame(1650, Rounding::percentHalfUp(7500, 2200));
        self::assertSame(1, Rounding::percentHalfUp(3, 2200), '0,66 centesimi → 1');
        self::assertSame(0, Rounding::percentHalfUp(2, 2200), '0,44 centesimi → 0');
    }
}
