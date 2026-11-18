<?php

declare(strict_types=1);

namespace Tests\Integration\Settings;

use App\Settings\EnvImport;
use App\Settings\SettingsService;
use App\Support\Config;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

/** D-021: le manopole degli ordini al fornitore e del catalogo stanno nell'admin; i vecchi valori del .env si copiano una volta. */
final class EnvImportTest extends TestCase
{
    public function testOldEnvValuesAreCopiedOnceAndNeverOverrideTheAdmin(): void
    {
        $app = new TestApp(['WTB_ORDER_TRIGGER' => 'manual', 'WTB_MAX_ORDER_EUR' => '250', 'WTB_CATALOG_ALL' => '1', 'WTB_SHIP_TO_EMAIL' => 'admin', 'WTB_PAYMENT_METHOD' => 'card']);
        $settings = $app->get(SettingsService::class);
        $import = new EnvImport($app->get(Config::class), $settings);

        $lines = $import->run();
        self::assertSame('manual', $settings->string('supplier.order_trigger'));
        self::assertSame(25000, $settings->int('supplier.max_order'));
        self::assertTrue($settings->bool('catalog.supplier_all'));
        self::assertSame(EnvImport::BY, $settings->audit('supplier.order_trigger', 1)[0]['changed_by']);
        self::assertSame('bank_transfer', $settings->string('supplier.payment_method'), 'Valore non valido: resta il default');
        self::assertFalse($settings->isCustomized('supplier.ship_to_email'), 'Uguale al default: niente da copiare');
        self::assertCount(5, $lines, 'Una riga per ogni variabile ancora nel .env');
        foreach ($lines as $line) {
            self::assertStringContainsString('.env', $line);
        }

        // L'admin cambia idea (anche tornando al default): il .env non vince più.
        $settings->setValue('supplier.order_trigger', 'on_request', 'admin');
        $settings->reset('supplier.max_order', 'admin');
        $import->run();
        self::assertSame('on_request', $settings->string('supplier.order_trigger'));
        self::assertSame(0, $settings->int('supplier.max_order'));
    }

    public function testNothingToDoWithoutOldVariables(): void
    {
        $app = new TestApp();
        self::assertSame([], (new EnvImport($app->get(Config::class), $app->get(SettingsService::class)))->run());
    }

    public function testWholeCatalogSettingReachesTheSupplierCalls(): void
    {
        $app = new TestApp();
        $app->syncCatalog();
        self::assertSame([], array_filter($app->fixtures()->requests, static fn (array $r): bool => str_contains($r['url'], 'all=1')));

        $app = new TestApp();
        $app->setting('catalog.supplier_all', true);
        $app->syncCatalog();
        $calls = array_filter($app->fixtures()->requests, static fn (array $r): bool => str_contains($r['url'], '/products/'));
        self::assertNotSame([], $calls);
        foreach ($calls as $call) {
            self::assertStringContainsString('all=1', $call['url']);
        }
    }
}
