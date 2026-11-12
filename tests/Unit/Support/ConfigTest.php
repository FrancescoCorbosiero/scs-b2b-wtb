<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Config;
use App\Support\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testLiveModeIsRefusedOutsideProduction(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromArray('/tmp', ['APP_ENV' => 'development', 'WTB_MODE' => 'live']);
    }

    public function testLiveModeIsAllowedInProduction(): void
    {
        $config = Config::fromArray('/tmp', ['APP_ENV' => 'production', 'WTB_MODE' => 'live']);
        self::assertSame('live', $config->wtbMode());
    }

    public function testUnknownValuesAreRefused(): void
    {
        foreach ([['WTB_MODE' => 'prod'], ['WTB_ORDER_TRIGGER' => 'sometimes'], ['WTB_PAYMENT_METHOD' => 'card'], ['APP_ENV' => 'staging']] as $bad) {
            try {
                Config::fromArray('/tmp', $bad + ['APP_ENV' => 'test']);
                self::fail('Valore accettato: ' . json_encode($bad));
            } catch (ConfigException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTypedGetters(): void
    {
        $config = Config::fromArray('/srv/app', [
            'APP_ENV' => 'test', 'X_INT' => '42', 'X_BOOL' => 'true', 'X_LIST' => ' a, b ,,c ', 'DB_PATH' => 'var/db.sqlite',
        ]);
        self::assertSame(42, $config->int('X_INT'));
        self::assertSame(7, $config->int('MISSING', 7));
        self::assertTrue($config->bool('X_BOOL'));
        self::assertSame(['a', 'b', 'c'], $config->list('X_LIST'));
        self::assertSame('/srv/app/var/db.sqlite', $config->dbPath());
        self::assertFalse($config->debug());
    }
}
