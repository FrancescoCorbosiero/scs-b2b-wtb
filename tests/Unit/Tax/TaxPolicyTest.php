<?php

declare(strict_types=1);

namespace Tests\Unit\Tax;

use App\Shipping\ShippingCalculator;
use App\Tax\TaxPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Totali per ogni combinazione paese × regime × spedizione (docs/10, M4). */
final class TaxPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, string, int, int, int, int, int, list<string>}>
     *  paese, UE, trattamento spedizione, margine, ordinario, spedizione, IVA attesa, totale atteso, diciture
     */
    public static function table(): iterable
    {
        // Italia
        yield 'IT solo margine, spedizione ordinaria' => ['IT', true, 'ordinary', 30000, 0, 1500, 330, 31830, ['tax.legend.margin']];
        yield 'IT solo margine, spedizione finale' => ['IT', true, 'final', 30000, 0, 1500, 0, 31500, ['tax.legend.margin']];
        yield 'IT solo ordinario' => ['IT', true, 'ordinary', 0, 20000, 1500, 4730, 26230, []];
        yield 'IT misto' => ['IT', true, 'ordinary', 30000, 20000, 1500, 4730, 56230, ['tax.legend.margin']];
        yield 'IT misto, spedizione finale' => ['IT', true, 'final', 30000, 20000, 1500, 4400, 55900, ['tax.legend.margin']];
        // Altro paese UE
        yield 'DE solo margine' => ['DE', true, 'ordinary', 30000, 0, 3000, 0, 33000, ['tax.legend.margin', 'tax.legend.intra_eu']];
        yield 'DE solo ordinario' => ['DE', true, 'ordinary', 0, 20000, 3000, 0, 23000, ['tax.legend.intra_eu']];
        yield 'DE misto, spedizione finale' => ['DE', true, 'final', 30000, 20000, 3000, 0, 53000, ['tax.legend.margin', 'tax.legend.intra_eu']];
        yield 'DE solo margine, spedizione finale' => ['DE', true, 'final', 30000, 0, 3000, 0, 33000, ['tax.legend.margin']];
        // Extra UE
        yield 'CH solo ordinario' => ['CH', false, 'ordinary', 0, 20000, 4500, 0, 24500, ['tax.legend.export']];
        yield 'GB misto' => ['GB', false, 'ordinary', 30000, 20000, 4500, 0, 54500, ['tax.legend.margin', 'tax.legend.export']];
        yield 'GB solo margine, spedizione finale' => ['GB', false, 'final', 30000, 0, 4500, 0, 34500, ['tax.legend.margin']];
        // Spedizione gratuita
        yield 'IT ordinario, spedizione gratis' => ['IT', true, 'ordinary', 0, 10000, 0, 2200, 12200, []];
    }

    /** @param list<string> $legends */
    #[DataProvider('table')]
    public function testTotals(string $country, bool $eu, string $shippingRegime, int $margin, int $ordinary, int $shipping, int $vat, int $total, array $legends): void
    {
        $result = TaxPolicy::calculate(TaxPolicy::scheme($country, $eu), 22, $shippingRegime, $margin, $ordinary, $shipping);
        self::assertSame($vat, $result->vatCents);
        self::assertSame($total, $result->totalCents);
        self::assertSame($legends, $result->legends);
        self::assertSame($result->subtotalMarginCents + $result->subtotalOrdinaryCents + $result->shippingCents + $result->vatCents, $result->totalCents);
    }

    public function testSchemesAndRounding(): void
    {
        self::assertSame('domestic', TaxPolicy::scheme('it', true));
        self::assertSame('intra_eu', TaxPolicy::scheme('FR', true));
        self::assertSame('export', TaxPolicy::scheme('CH', false));
        $r = TaxPolicy::calculate('domestic', 22, 'ordinary', 0, 7967, 0);
        self::assertSame(1753, $r->vatCents, '22% di 79,67 = 17,5274 → 17,53');
        self::assertSame(2200, $r->vatRateBp);
    }

    public function testShippingFee(): void
    {
        self::assertSame(1500, ShippingCalculator::fee(1500, null, 5));
        self::assertSame(1500, ShippingCalculator::fee(1500, 20, 19));
        self::assertSame(0, ShippingCalculator::fee(1500, 20, 20));
        self::assertSame(0, ShippingCalculator::fee(1500, null, 0));
    }
}
