<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /** @return iterable<array{string, int}> */
    public static function decimals(): iterable
    {
        yield ['63.84', 6384];
        yield ['0', 0];
        yield ['0.00', 0];
        yield ['12', 1200];
        yield ['12.5', 1250];
        yield ['1203.07', 120307];
        yield ['-1.20', -120];
    }

    #[DataProvider('decimals')]
    public function testParseWithoutFloats(string $input, int $cents): void
    {
        self::assertSame($cents, Money::parse($input));
    }

    public function testParseRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::parse('12,50');
    }

    public function testParseInputAcceptsHumanFormats(): void
    {
        self::assertSame(1550, Money::parseInput('15,50'));
        self::assertSame(1500, Money::parseInput('15'));
        self::assertSame(123456, Money::parseInput('1.234,56'));
        self::assertSame(1550, Money::parseInput('15.50'));
        self::assertSame(1550, Money::parseInput(' 15,50 € '));
        self::assertNull(Money::parseInput(''));
        self::assertNull(Money::parseInput('abc'));
        self::assertNull(Money::parseInput('15,555'));
    }

    public function testFormatting(): void
    {
        self::assertSame('63.84', Money::toDecimal(6384));
        self::assertSame('0.05', Money::toDecimal(5));
        self::assertSame('-1.20', Money::toDecimal(-120));
        self::assertSame("1.234,56\u{a0}€", Money::format(123456, 'it'));
        self::assertSame('€1,234.56', Money::format(123456, 'en'));
        self::assertSame('15,00', Money::formatInput(1500));
    }
}
