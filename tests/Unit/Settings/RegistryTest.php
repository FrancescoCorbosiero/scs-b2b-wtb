<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use App\Settings\Definition;
use App\Settings\InvalidSetting;
use App\Settings\Registry;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    public function testEveryDefaultIsValidAndRoundTrips(): void
    {
        foreach ((new Registry())->all() as $key => $definition) {
            $definition->check($definition->default);
            self::assertSame($definition->default, $definition->decode($definition->encode($definition->default)), $key);
            self::assertSame($definition->default, $definition->parseInput($definition->formatInput($definition->default)), $key);
            self::assertArrayHasKey($definition->group, Registry::GROUPS, $key);
        }
    }

    public function testDocumentedKnobsExist(): void
    {
        $registry = new Registry();
        foreach ([
            'orders.min_items' => 5, 'orders.rethink_seconds' => 15, 'catalog.sell_extended' => true,
            'catalog.regimes' => 'margin,ordinary', 'catalog.per_page' => 24, 'catalog.availability_high_min' => 60,
            'catalog.availability_low_max' => 20, 'catalog.sold_out_days' => 30, 'prices.rounding' => 'whole',
            'prices.default_margin_type' => 'percent', 'prices.default_margin_value' => '5.00', 'tax.vat_rate_it' => 22,
            'tax.shipping_regime' => 'ordinary', 'images.proxy' => true,
        ] as $key => $default) {
            self::assertSame($default, $registry->get($key)->default, $key);
        }
        self::assertContains('brt.it', $registry->get('shipping.carrier_domains')->default);
    }

    public function testParsingByType(): void
    {
        $r = new Registry();
        self::assertSame(8, $r->get('orders.min_items')->parseInput('8'));
        self::assertSame('7.50', $r->get('prices.default_margin_value')->parseInput('7,5'));
        self::assertFalse($r->get('catalog.sell_extended')->parseInput('0'));
        self::assertSame('half', $r->get('prices.rounding')->parseInput('half'));
        self::assertSame(['brt.it', 'dhl.com'], $r->get('shipping.carrier_domains')->parseInput("BRT.it\ndhl.com, brt.it"));
        $money = new Definition('x.fee', 'money', 1500, 'Fee', 'orders', min: 0);
        self::assertSame(1250, $money->parseInput('12,50'));
    }

    public function testInvalidInputsAreRejected(): void
    {
        $r = new Registry();
        foreach ([
            ['orders.min_items', '0'], ['orders.min_items', 'cinque'], ['prices.rounding', 'down'],
            ['prices.default_margin_value', '5,555'], ['company.email', 'non-una-email'],
            ['shipping.carrier_domains', 'not a domain!'], ['company.website', 'http://insicuro.example'],
            ['company.whatsapp', '+39 333'],
        ] as [$key, $input]) {
            try {
                $r->get($key)->parseInput($input);
                self::fail("Accettato $key = $input");
            } catch (InvalidSetting) {
                self::addToAssertionCount(1);
            }
        }
    }
}
