<?php

declare(strict_types=1);

namespace Tests\Integration\Settings;

use App\Settings\InvalidSetting;
use App\Settings\SettingsService;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class SettingsServiceTest extends TestCase
{
    public function testSetResetAndAudit(): void
    {
        $app = new TestApp();
        $settings = $app->get(SettingsService::class);
        $changed = [];
        $settings->onChange(static function (string $key) use (&$changed): void {
            $changed[] = $key;
        });

        self::assertSame(5, $settings->int('orders.min_items'));
        self::assertFalse($settings->isCustomized('orders.min_items'));

        $settings->setFromInput('orders.min_items', '8', 'Titolare');
        self::assertSame(8, $settings->int('orders.min_items'));
        self::assertTrue($settings->isCustomized('orders.min_items'));

        $settings->setFromInput('orders.min_items', '8', 'Titolare');
        self::assertCount(1, $settings->audit('orders.min_items'), 'Stesso valore: nessuna nuova riga di storico');

        $settings->reset('orders.min_items', 'Titolare');
        self::assertSame(5, $settings->int('orders.min_items'));
        $audit = $settings->audit('orders.min_items');
        self::assertCount(2, $audit);
        self::assertNull($audit[0]['new_value']);
        self::assertSame('8', $audit[0]['old_value']);
        self::assertSame('Titolare', $audit[0]['changed_by']);
        self::assertSame(['orders.min_items', 'orders.min_items'], $changed);

        $this->expectException(InvalidSetting::class);
        $settings->setFromInput('orders.min_items', '-3', 'Titolare');
    }
}
