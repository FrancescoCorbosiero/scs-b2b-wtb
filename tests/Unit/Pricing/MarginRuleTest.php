<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Pricing\MarginResolver;
use App\Pricing\MarginRule;
use App\Pricing\Tier;
use App\Pricing\TierCalculator;
use PHPUnit\Framework\TestCase;

final class MarginRuleTest extends TestCase
{
    /** @return array<string, mixed> */
    private function product(): array
    {
        return [
            'sku' => 'ZX1007-700', 'name' => 'New Balance 9060 Demo Rain Cloud Grey', 'brand' => 'New Balance', 'line' => '9060',
            'gender' => 'unisex', 'category_path' => '["Sneakers","New Balance","9060","Unisex"]', 'size_category' => 'adult',
        ];
    }

    private function rule(string $type, string $value, ?string $regime = null, ?string $stock = null, int $priority = 100, int $id = 1): MarginRule
    {
        return new MarginRule($id, $priority, $type, $value, $regime, $stock, 'percent', 1000, true);
    }

    public function testMatching(): void
    {
        $p = $this->product();
        self::assertTrue($this->rule('sku', 'AAA-1, zx1007-700')->matches($p, 'margin', 'immediate'));
        self::assertTrue($this->rule('brand', 'new balance')->matches($p, 'margin', 'immediate'));
        self::assertFalse($this->rule('brand', 'New')->matches($p, 'margin', 'immediate'), 'Il brand deve combaciare per intero');
        self::assertTrue($this->rule('name', 'rain cloud')->matches($p, 'margin', 'immediate'));
        self::assertTrue($this->rule('line', '9060')->matches($p, 'margin', 'immediate'));
        self::assertTrue($this->rule('gender', 'unisex')->matches($p, 'margin', 'immediate'));
        self::assertTrue($this->rule('category', 'new balance / 9060')->matches($p, 'margin', 'immediate'));
        self::assertTrue($this->rule('size_category', 'adult')->matches($p, 'margin', 'immediate'));
        self::assertFalse($this->rule('brand', 'New Balance', 'ordinary')->matches($p, 'margin', 'immediate'), 'Solo un regime');
        self::assertFalse($this->rule('brand', 'New Balance', null, 'extended')->matches($p, 'margin', 'immediate'), 'Solo un tipo di stock');
    }

    public function testSkuRulesComeFirstThenPriority(): void
    {
        $rules = [
            $this->rule('brand', 'New Balance', priority: 1, id: 1),
            $this->rule('sku', 'ZX1007-700', priority: 999, id: 2),
            $this->rule('line', '9060', priority: 5, id: 3),
        ];
        usort($rules, static fn (MarginRule $a, MarginRule $b): int => strcmp($a->sortKey(), $b->sortKey()));
        self::assertSame([2, 1, 3], array_map(static fn (MarginRule $r): int => $r->id, $rules));
    }

    public function testNetMarginEstimate(): void
    {
        // Vendita 122, costo 100: margine 22, IVA sul margine 22/122 di 22 = 3,97 → netto 18,03.
        self::assertSame(1803, MarginResolver::netMarginEstimate(12200, 10000, 'margin', 22));
        self::assertSame(2200, MarginResolver::netMarginEstimate(12200, 10000, 'ordinary', 22), 'Ordinario: il netto è il margine');
        self::assertSame(-500, MarginResolver::netMarginEstimate(9500, 10000, 'margin', 22));
    }

    public function testTiersImmediateFirst(): void
    {
        $tiers = [new Tier('extended', 6, 7700, '3-5'), new Tier('immediate', 3, 7500, '1-2')];
        $split = TierCalculator::split($tiers, 5);
        self::assertSame([['immediate', 3, 7500], ['extended', 2, 7700]], array_map(static fn (array $l): array => [$l['stock_type'], $l['quantity'], $l['unit_price_cents']], $split['lines']));
        self::assertSame(3 * 7500 + 2 * 7700, $split['total_cents']);
        self::assertSame(9, $split['max']);
        self::assertSame(9, TierCalculator::split($tiers, 50)['quantity'], 'Non si supera il disponibile');
        self::assertSame(0, TierCalculator::split($tiers, 0)['total_cents']);
    }
}
