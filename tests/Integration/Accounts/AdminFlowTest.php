<?php

declare(strict_types=1);

namespace Tests\Integration\Accounts;

use App\Settings\SettingsService;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class AdminFlowTest extends TestCase
{
    public function testAdminAreaRequiresLogin(): void
    {
        $http = (new TestApp())->http();
        foreach (['/admin', '/admin/impostazioni', '/admin/clienti'] as $path) {
            $response = $http->get($path);
            self::assertSame(303, $response->getStatusCode(), $path);
            self::assertSame('/admin/login', $response->getHeaderLine('Location'));
        }
    }

    public function testSettingChangedByAdminAppearsInHistory(): void
    {
        $app = new TestApp();
        $http = $app->http();
        $http->loginAdmin();
        self::assertSame(200, $http->get('/admin')->getStatusCode());
        self::assertStringContainsString('FIXTURE', $http->body(), 'Banner della modalità sempre visibile');

        $http->post('/admin/impostazioni/orders.min_items', ['value' => '7']);
        self::assertSame(303, $http->last->getStatusCode());
        self::assertSame(7, $app->get(SettingsService::class)->int('orders.min_items'));

        $page = (string) $http->get('/admin/impostazioni')->getBody();
        self::assertStringContainsString('Ordine minimo (paia)', $page);
        self::assertStringContainsString('5 (default) → ', $page);
        self::assertStringContainsString('Test', $page, 'Nome di chi ha cambiato');

        $http->post('/admin/impostazioni/orders.min_items/ripristina');
        self::assertSame(5, $app->get(SettingsService::class)->int('orders.min_items'));

        $http->post('/admin/impostazioni/orders.min_items', ['value' => 'zero']);
        self::assertSame(5, $app->get(SettingsService::class)->int('orders.min_items'), 'Valore non valido ignorato');
    }

    public function testAdminLockoutAfterFiveFailuresPerIp(): void
    {
        $app = new TestApp();
        $http = $app->http('198.51.100.1');
        for ($i = 0; $i < 5; $i++) {
            $http->post('/admin/login', ['password' => 'sbagliata']);
        }
        $http->post('/admin/login', ['password' => TestApp::ADMIN_PASSWORD]);
        self::assertSame('/admin/login', $http->last->getHeaderLine('Location'), 'Bloccato anche con la password giusta');

        $other = $app->http('198.51.100.2');
        $other->loginAdmin();
        self::assertSame(200, $other->get('/admin')->getStatusCode(), 'Un altro IP non condivide il contatore');

        $app->clock->advance(16 * 60);
        $http->loginAdmin();
        self::assertSame(200, $http->get('/admin')->getStatusCode(), 'Sblocco dopo 15 minuti');
    }

    public function testRealIpBehindTrustedProxy(): void
    {
        $app = new TestApp();
        $viaProxy = $app->http('10.0.0.5');
        for ($i = 0; $i < 5; $i++) {
            $viaProxy->post('/admin/login', ['password' => 'sbagliata'], ['X-Forwarded-For' => '203.0.113.50']);
        }
        $other = $app->http('10.0.0.5');
        $other->post('/admin/login', ['password' => TestApp::ADMIN_PASSWORD, 'name' => 'B'], ['X-Forwarded-For' => '203.0.113.51']);
        self::assertSame('/admin', $other->last->getHeaderLine('Location'), 'Due client dietro lo stesso proxy non condividono il blocco');
    }

    public function testCsrfIsRequiredOnPost(): void
    {
        $app = new TestApp();
        $http = $app->http();
        $http->loginAdmin();
        $response = $http->post('/admin/impostazioni/orders.min_items', ['value' => '9', '_csrf' => 'sbagliato']);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(5, $app->get(SettingsService::class)->int('orders.min_items'));
        $response = $http->post('/admin/impostazioni/orders.min_items', ['value' => '9'], [], false);
        self::assertSame(403, $response->getStatusCode());
    }
}
