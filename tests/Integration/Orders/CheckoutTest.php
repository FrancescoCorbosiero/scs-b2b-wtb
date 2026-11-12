<?php

declare(strict_types=1);

namespace Tests\Integration\Orders;

use App\Orders\Cart;
use App\Supplier\Wtb\Http\TransportException;
use App\Supplier\Wtb\Http\TransportResponse;
use PHPUnit\Framework\TestCase;
use Tests\Support\HttpClient;
use Tests\Support\OrderHelpers;
use Tests\Support\TestApp;

final class CheckoutTest extends TestCase
{
    use OrderHelpers;

    private function boot(array $customer = []): array
    {
        $app = new TestApp();
        $app->syncCatalog();
        $app->createCustomer($customer);
        $http = $app->http();
        $http->loginCustomer($customer['email'] ?? 'cliente@example.com');

        return [$app, $http];
    }

    public function testFullFlowCreatesAFrozenOrderAndEmails(): void
    {
        /** @var TestApp $app */ /** @var HttpClient $http */
        [$app, $http] = $this->boot();
        $this->fillCart($app, $http, [90011 => 3, 90014 => 2]);
        $form = $this->checkoutForm($http);
        $http->post('/checkout', $form);
        self::assertMatchesRegularExpression('#^/checkout/completato/1001$#', $http->last->getHeaderLine('Location'));

        $order = $app->db->one('SELECT * FROM orders WHERE id = 1001');
        self::assertSame('pending', $order['status']);
        self::assertSame('fixture', $order['mode']);
        self::assertSame(5, $order['pairs']);
        self::assertSame(37700, $order['subtotal_margin_cents'], '2×75 + 1×77 + 2×75');
        self::assertSame(1500, $order['shipping_cents']);
        self::assertSame(330, $order['vat_cents'], '22% sulla spedizione (trattamento ordinario)');
        self::assertSame(39530, $order['total_cents']);
        self::assertSame('domestic', $order['vat_scheme']);
        self::assertSame(0, $order['quote_unverified']);
        self::assertSame(35867, $order['expected_cost_cents'], 'Costo merce dal preventivo');
        self::assertNotNull($order['quote_snapshot']);
        self::assertSame('Consegna al mattino', $order['notes']);
        $line = $app->db->one("SELECT * FROM order_lines WHERE order_id = 1001 AND size_eu = '36'");
        self::assertSame([2, 7500, 1, 7700], [$line['qty_immediate'], $line['unit_price_immediate_cents'], $line['qty_extended'], $line['unit_price_extended_cents']]);
        self::assertSame([7137, 7319], [$line['unit_cost_immediate_cents'], $line['unit_cost_extended_cents']]);

        self::assertSame([], (new Cart($http->session()))->items(), 'Carrello svuotato');
        $customerMail = $app->mail->sentTo('cliente@example.com');
        self::assertCount(1, $customerMail);
        self::assertStringContainsString('IT60X0542811101000000123456', $customerMail[0]->text, 'Istruzioni di bonifico');
        self::assertStringContainsString('Ordine 1001', $customerMail[0]->text, 'Causale = numero d\'ordine');
        $adminMail = $app->mail->sentTo('info@shoesclothingstore.com');
        self::assertCount(1, $adminMail);
        self::assertStringContainsString('71,37', $adminMail[0]->html, 'All\'admin anche i costi');
        self::assertSame(1, $app->db->int('SELECT email_customer_sent + email_admin_sent - 1 FROM orders WHERE id = 1001'));

        $done = (string) $http->get('/checkout/completato/1001')->getBody();
        self::assertStringContainsString('IT60X0542811101000000123456', $done);
        self::assertStringContainsString('395,30', $done);

        // Lo stesso invio (doppio clic, ricarica) non crea un secondo ordine.
        $http->post('/checkout', $form);
        self::assertSame('/checkout/completato/1001', $http->last->getHeaderLine('Location'));
        self::assertSame(1, $app->db->int('SELECT COUNT(*) FROM orders'));
    }

    public function testSupplierStockShortageReducesTheCartAndAsksToConfirm(): void
    {
        [$app, $http] = $this->boot();
        $this->fillCart($app, $http, [90071 => 6]);
        $app->fixtures()->queue('POST', '/quote/', TransportResponse::json(400, ['stock' => ['ZX1007-700 EU 40 (margin): only 4 available.']]));
        $http->post('/checkout', $this->checkoutForm($http));
        self::assertSame('/checkout', $http->last->getHeaderLine('Location'));
        self::assertSame(0, $app->db->int('SELECT COUNT(*) FROM orders'));
        self::assertSame(4, (new Cart($http->session()))->items()[$this->unitKey($app, 90071)]);
        $html = (string) $http->get('/checkout')->getBody();
        self::assertStringContainsString('disponibili 4 paia invece di 6', $html);
    }

    public function testFewerImmediatePairsThanExpectedUpdatesPricesBeforeConfirming(): void
    {
        [$app, $http] = $this->boot();
        // Il nostro database dice 6 in pronta consegna; il fornitore (fixture) ne ha 4 e passa 2 paia allo stock esteso.
        $app->db->run("UPDATE offers SET available = 6 WHERE stock_type = 'immediate' AND variant_id = (SELECT id FROM variants WHERE wtb_variant_id = 90071)");
        $this->fillCart($app, $http, [90071 => 6]);
        $http->post('/checkout', $this->checkoutForm($http));
        self::assertSame('/checkout', $http->last->getHeaderLine('Location'));
        $html = (string) $http->get('/checkout')->getBody();
        self::assertStringContainsString('Prezzi aggiornati', $html);
        self::assertStringContainsString('4 × 151,00', $html, 'Riepilogo sulla ripartizione del fornitore');
        $http->post('/checkout', $this->checkoutForm($http));
        self::assertSame('/checkout/completato/1001', $http->last->getHeaderLine('Location'));
        $line = $app->db->one('SELECT * FROM order_lines WHERE order_id = 1001');
        self::assertSame([4, 2], [$line['qty_immediate'], $line['qty_extended']]);
    }

    public function testUnreachableSupplierAcceptsWithLocalCheckOnly(): void
    {
        [$app, $http] = $this->boot();
        $this->fillCart($app, $http, [90061 => 1, 90063 => 4]);
        foreach (range(1, 3) as $i) {
            $app->fixtures()->queue('POST', '/quote/', new TransportException('timeout'));
        }
        $http->post('/checkout', $this->checkoutForm($http));
        self::assertSame('/checkout/completato/1001', $http->last->getHeaderLine('Location'));
        $order = $app->db->one('SELECT * FROM orders WHERE id = 1001');
        self::assertSame(1, $order['quote_unverified']);
        self::assertNull($order['wtb_shipping_cost_cents']);
        self::assertSame(5 * 13729, $order['expected_cost_cents'], 'Costi dalle offerte locali');
    }

    public function testSmtpDownNeverLosesTheOrder(): void
    {
        [$app, $http] = $this->boot();
        $app->mail->failing = true;
        $this->fillCart($app, $http, [90071 => 5]);
        $http->post('/checkout', $this->checkoutForm($http));
        self::assertSame('/checkout/completato/1001', $http->last->getHeaderLine('Location'));
        self::assertSame([0, 0], array_values($app->db->one('SELECT email_customer_sent, email_admin_sent FROM orders WHERE id = 1001')));
    }

    public function testMinimumRateLimitAndValidation(): void
    {
        [$app, $http] = $this->boot();
        $this->fillCart($app, $http, [90071 => 2]);
        $http->post('/checkout', $this->checkoutForm($http));
        self::assertSame(0, $app->db->int('SELECT COUNT(*) FROM orders'), 'Sotto il minimo non parte');

        $this->fillCart($app, $http, [90071 => 5]);
        $http->post('/checkout', $this->checkoutForm($http, ['ship_to_mode' => 'other', 'ship_name' => 'X', 'ship_street' => 'Via 1', 'ship_city' => 'Roma', 'ship_zip' => '12', 'ship_country' => 'IT']));
        self::assertSame(0, $app->db->int('SELECT COUNT(*) FROM orders'));
        self::assertStringContainsString('Il CAP italiano ha 5 cifre', (string) $http->get('/checkout')->getBody());

        for ($i = 1; $i <= 4; $i++) {
            $this->fillCart($app, $http, [90071 => 5]);
            $http->post('/checkout', $this->checkoutForm($http));
        }
        self::assertSame(3, $app->db->int('SELECT COUNT(*) FROM orders'), 'Massimo 3 richieste all\'ora per account');
    }

    public function testVatByBillingCountryAndShipToOtherCountry(): void
    {
        [$app, $http] = $this->boot(['email' => 'kunde@example.com', 'country_code' => 'DE', 'vat_number' => 'DE123456789', 'address_zip' => '10115', 'address_province' => '', 'locale' => 'en']);
        $this->fillCart($app, $http, [90091 => 1, 90054 => 1, 90071 => 3], 'ordinary');
        $this->fillCart($app, $http, [90071 => 3]);
        $http->post('/checkout', $this->checkoutForm($http, ['ship_to_mode' => 'other', 'ship_name' => 'Lager', 'ship_street' => 'Rue 1', 'ship_city' => 'Paris', 'ship_zip' => '75001', 'ship_country' => 'FR', 'email' => 'kunde@example.com']));
        self::assertSame('/checkout/completato/1001', $http->last->getHeaderLine('Location'));
        $order = $app->db->one('SELECT * FROM orders WHERE id = 1001');
        self::assertSame('intra_eu', $order['vat_scheme']);
        self::assertSame(0, $order['vat_cents']);
        self::assertSame('FR', $order['ship_country']);
        self::assertSame('EU', $order['shipping_zone']);
        self::assertSame('en', $order['locale']);
        self::assertStringContainsString('Order request no. 1001', $app->mail->sentTo('kunde@example.com')[0]->subject);
    }
}
