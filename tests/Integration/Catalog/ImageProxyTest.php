<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog;

use App\Catalog\ImageService;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class ImageProxyTest extends TestCase
{
    private string $cache;

    protected function setUp(): void
    {
        $this->cache = sys_get_temp_dir() . '/scs-img-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cache . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->cache . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->cache);
    }

    private function app(): TestApp
    {
        $app = new TestApp([], [ImageService::class => \DI\factory(fn (\Psr\Container\ContainerInterface $c) => new ImageService(
            $c->get(\App\Support\Db::class),
            $c->get(\App\Supplier\Wtb\WtbConfig::class),
            $c->get(\App\Support\Clock::class),
            new \Psr\Log\NullLogger(),
            $this->cache,
        ))]);
        $app->syncCatalog();

        return $app;
    }

    public function testImagesAreServedFromOurDomainWithLongCache(): void
    {
        $app = $this->app();
        $productId = $app->db->int("SELECT id FROM products WHERE sku = 'ZX1001-100'");
        $response = $app->http()->get('/img/' . $productId . '/0-400.jpg');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/jpeg', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('immutable', $response->getHeaderLine('Cache-Control'));
        self::assertSame('noindex, nofollow', $response->getHeaderLine('X-Robots-Tag'));
        self::assertEmpty($response->getHeader('Set-Cookie'), 'Le immagini non aprono sessioni');
        $size = getimagesizefromstring((string) $response->getBody());
        self::assertNotFalse($size);
        self::assertSame(400, $size[0], 'Miniatura a 400 px');
        self::assertSame('ok', $app->db->value('SELECT status FROM product_images WHERE product_id = :p AND position = 0', ['p' => $productId]));
    }

    public function testUnknownImageGetsAPlaceholderAndForeignHostsAreRefused(): void
    {
        $app = $this->app();
        $response = $app->http()->get('/img/999999/0-800.jpg');
        self::assertSame('image/svg+xml', $response->getHeaderLine('Content-Type'));

        $productId = $app->db->int("SELECT id FROM products WHERE sku = 'ZX1004-400'");
        $app->db->run("UPDATE product_images SET source_url = 'https://evil.example/x.jpg' WHERE product_id = :p AND position = 0", ['p' => $productId]);
        $response = $app->http()->get('/img/' . $productId . '/0-800.jpg');
        self::assertSame('image/svg+xml', $response->getHeaderLine('Content-Type'), 'Host fuori whitelist: segnaposto');
        self::assertSame('error', $app->db->value('SELECT status FROM product_images WHERE product_id = :p AND position = 0', ['p' => $productId]));
        self::assertSame(404, $app->http()->get('/img/1/0-123.jpg')->getStatusCode(), 'Larghezze ammesse: 0, 400, 800');
    }

    public function testPublicUrlChangesWhenTheSourceChanges(): void
    {
        self::assertNotSame(ImageService::publicUrl(1, 0, 'https://a/x.jpg'), ImageService::publicUrl(1, 0, 'https://a/y.jpg'));
        self::assertStringStartsWith('/img/1/0-800.jpg?v=', ImageService::publicUrl(1, 0, 'https://a/x.jpg'));
    }
}
