<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class SmokeTest extends TestCase
{
    public function testHomeLoginAndAdminLoginRender(): void
    {
        $app = new TestApp();
        $http = $app->http();

        $home = $http->get('/');
        self::assertSame(200, $home->getStatusCode(), (string) $home->getBody());
        self::assertStringContainsString('SCS B2B', (string) $home->getBody());
        self::assertFalse($home->hasHeader('X-Robots-Tag'), 'La home è indicizzabile');

        $login = $http->get('/login');
        self::assertSame(200, $login->getStatusCode());
        self::assertSame('noindex, nofollow', $login->getHeaderLine('X-Robots-Tag'));
        self::assertStringContainsString("default-src 'self'", $login->getHeaderLine('Content-Security-Policy'));

        self::assertSame(200, $http->get('/admin/login')->getStatusCode());
        self::assertSame(404, $http->get('/non-esiste')->getStatusCode());
    }
}
