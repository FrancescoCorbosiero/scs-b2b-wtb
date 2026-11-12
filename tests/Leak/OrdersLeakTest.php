<?php

declare(strict_types=1);

namespace Tests\Leak;

use App\Orders\OrderRepository;
use App\Orders\ProformaPdf;
use PHPUnit\Framework\TestCase;
use Tests\Support\LeakAssertions;
use Tests\Support\OrderHelpers;
use Tests\Support\TestApp;

/** Carrello, checkout, ordini del cliente, email e pro-forma: mai costi, mai il fornitore. */
final class OrdersLeakTest extends TestCase
{
    use LeakAssertions;
    use OrderHelpers;

    public function testCartCheckoutOrdersEmailsAndPdf(): void
    {
        $app = new TestApp();
        $app->syncCatalog();
        $app->createCustomer();
        $http = $app->http();
        $http->loginCustomer();

        $this->fillCart($app, $http, [90011 => 3, 90014 => 2, 90071 => 2]);
        $json = $http->postJson('/carrello/aggiungi', ['qty' => [$this->unitKey($app, 90054, 'ordinary') => 1]]);
        $this->assertResponseHasNoLeak($app, $json, 'JSON del carrello');
        foreach (['/carrello', '/checkout', '/catalogo/prodotto/ZX1001-100?partial=1'] as $path) {
            $this->assertResponseHasNoLeak($app, $http->get($path), $path);
        }
        $http->post('/checkout', $this->checkoutForm($http));
        foreach (['/checkout/completato/1001', '/account/ordini', '/account/ordini/1001'] as $path) {
            $response = $http->get($path);
            self::assertSame(200, $response->getStatusCode(), $path);
            $this->assertResponseHasNoLeak($app, $response, $path);
        }

        $admin = $app->http('198.51.100.210');
        $admin->loginAdmin();
        $admin->post('/admin/ordini/1001/conferma');
        $line = $app->db->int('SELECT id FROM order_lines WHERE order_id = 1001 AND qty_immediate + qty_extended >= 2 ORDER BY id LIMIT 1');
        $admin->post('/admin/ordini/1001/riallinea', ['qty' => [$line => '1'], 'notify' => '1']);

        $pdf = $http->get('/account/ordini/1001/proforma.pdf');
        $this->assertResponseHasNoLeak($app, $pdf, 'PDF (byte e header)');
        $order = $app->get(OrderRepository::class)->find(1001);
        self::assertNotNull($order);
        $this->assertNoLeak($app, $app->get(ProformaPdf::class)->html($order, $app->get(OrderRepository::class)->lines(1001)), 'HTML della pro-forma');

        $customerMails = $app->mail->sentTo('cliente@example.com');
        self::assertCount(3, $customerMails, 'Richiesta, conferma, aggiornamento');
        foreach ($customerMails as $email) {
            $content = $email->subject . $email->html . $email->text;
            foreach ($email->attachments as $attachment) {
                $content .= $attachment['name'];
            }
            $this->assertNoLeak($app, $content, 'email "' . $email->subject . '"');
        }
    }
}
