<?php

declare(strict_types=1);

namespace Tests\Integration\Orders;

use App\Orders\Cart;
use PHPUnit\Framework\TestCase;
use Tests\Support\HttpClient;
use Tests\Support\OrderHelpers;
use Tests\Support\TestApp;

final class CartTest extends TestCase
{
    use OrderHelpers;

    private TestApp $app;
    private HttpClient $http;

    protected function setUp(): void
    {
        $this->app = new TestApp();
        $this->app->syncCatalog();
        $this->app->createCustomer();
        $this->http = $this->app->http();
        $this->http->loginCustomer();
    }

    public function testQuickViewAddUsesSetSemanticsAndTiers(): void
    {
        $this->fillCart($this->app, $this->http, [90011 => 4]);
        $this->fillCart($this->app, $this->http, [90011 => 3]);
        self::assertSame([$this->unitKey($this->app, 90011) => 3], (new Cart($this->http->session()))->items(), 'La scheda imposta le quantità, non le somma');
        $html = (string) $this->http->get('/carrello')->getBody();
        self::assertStringContainsString('227,00', $html, '2 × 75,00 in pronta consegna + 1 × 77,00 in stock esteso');
        self::assertStringContainsString('Ordine minimo 5 paia: ne mancano 2', $html);
        self::assertStringContainsString('disabled', $html);
        self::assertMatchesRegularExpression('/data-cart-count>3</', $html, 'Badge nel menu');
    }

    public function testJsonAddReturnsSummaryWithoutCosts(): void
    {
        $response = $this->http->postJson('/carrello/aggiungi', ['qty' => [$this->unitKey($this->app, 90071) => 6]]);
        $json = json_decode((string) $response->getBody(), true);
        self::assertTrue($json['ok']);
        self::assertSame(6, $json['cart']['pairs']);
        self::assertTrue($json['cart']['meets_minimum']);
        self::assertStringNotContainsString('cost', (string) $response->getBody());
    }

    public function testTakeAllClearProductAndDestination(): void
    {
        $this->fillCart($this->app, $this->http, [90011 => 1, 90041 => 1]);
        $productId = $this->app->db->int("SELECT id FROM products WHERE sku = 'ZX1001-100'");
        $this->http->post('/carrello/aggiorna', ['take_all' => (string) $productId]);
        $items = (new Cart($this->http->session()))->items();
        self::assertSame(8, $items[$this->unitKey($this->app, 90011)], '2 in pronta + 6 in esteso');
        self::assertSame(2, $items[$this->unitKey($this->app, 90014)]);
        $this->http->post('/carrello/aggiorna', ['clear_product' => (string) $productId]);
        $items = (new Cart($this->http->session()))->items();
        self::assertArrayNotHasKey($this->unitKey($this->app, 90011), $items);
        self::assertSame(1, $items[$this->unitKey($this->app, 90041)]);

        $this->http->post('/carrello/aggiorna', ['destination' => 'DE']);
        $html = (string) $this->http->get('/carrello')->getBody();
        self::assertStringContainsString('Spedizione (Unione Europea)', $html);
        self::assertStringContainsString('30,00', $html);
        $this->http->post('/carrello/svuota');
        self::assertSame([], (new Cart($this->http->session()))->items());
    }

    public function testStockRecheckReducesTheCart(): void
    {
        $this->fillCart($this->app, $this->http, [90061 => 1, 90062 => 3]);
        $this->app->db->run("UPDATE offers SET available = 1 WHERE variant_id = (SELECT id FROM variants WHERE wtb_variant_id = 90062)");
        $html = (string) $this->http->get('/carrello')->getBody();
        self::assertStringContainsString('disponibili 1 paia invece di 3', $html);
        self::assertSame(1, (new Cart($this->http->session()))->items()[$this->unitKey($this->app, 90062)]);
    }
}
