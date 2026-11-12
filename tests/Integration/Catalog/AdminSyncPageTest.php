<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

use App\Supplier\Wtb\WtbClient;
use App\Support\SystemFlags;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class AdminSyncPageTest extends TestCase
{
    public function testSyncNowFromAdminAndProfileSnapshot(): void
    {
        $app = new TestApp();
        $http = $app->http();
        $http->loginAdmin();
        $http->post('/admin/sync/products_sync');
        self::assertSame('/admin/sync', $http->last->getHeaderLine('Location'));
        self::assertSame(10, $app->db->int('SELECT COUNT(*) FROM products'));
        self::assertSame('admin', $app->db->value("SELECT trigger FROM job_runs WHERE job = 'products_sync'"));

        $page = (string) $http->get('/admin/sync')->getBody();
        self::assertStringContainsString('Zona Italia', $page, 'Tariffe del fornitore visibili in admin');
        self::assertStringContainsString('9,30', $page);
        self::assertSame(404, $http->post('/admin/sync/non_esiste')->getStatusCode());
    }

    public function testDashboardShowsTheAuthAlertUntilItClears(): void
    {
        $app = new TestApp();
        $app->get(SystemFlags::class)->setJson(WtbClient::FLAG_AUTH_ERROR, ['message' => 'x']);
        $http = $app->http();
        $http->loginAdmin();
        self::assertStringContainsString('Chiave del fornitore rifiutata', (string) $http->get('/admin')->getBody());
        $app->syncCatalog();
        self::assertStringNotContainsString('Chiave del fornitore rifiutata', (string) $http->get('/admin')->getBody());
    }
}
