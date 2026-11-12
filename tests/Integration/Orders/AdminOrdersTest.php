<?php

declare(strict_types=1);

namespace Tests\Integration\Orders;

use PHPUnit\Framework\TestCase;
use Tests\Support\HttpClient;
use Tests\Support\OrderHelpers;
use Tests\Support\TestApp;

final class AdminOrdersTest extends TestCase
{
    use OrderHelpers;

    /** @return array{0: TestApp, 1: HttpClient, 2: HttpClient} */
    private function withOrder(array $customer = [], array $cart = [90011 => 3, 90014 => 2], string $regime = 'margin'): array
    {
        $app = new TestApp();
        $app->syncCatalog();
        $app->createCustomer($customer);
        $http = $app->http();
        $http->loginCustomer($customer['email'] ?? 'cliente@example.com');
        $this->fillCart($app, $http, $cart, $regime);
        $http->post('/checkout', $this->checkoutForm($http, ['email' => $customer['email'] ?? 'cliente@example.com']));
        self::assertSame(1, $app->db->int('SELECT COUNT(*) FROM orders'), 'Ordine creato');
        $admin = $app->http('198.51.100.200');
        $admin->loginAdmin();

        return [$app, $http, $admin];
    }

    public function testConfirmAssignsReceiptAndSendsThePdf(): void
    {
        [$app, $customer, $admin] = $this->withOrder();
        self::assertSame(404, $customer->get('/account/ordini/1001/proforma.pdf')->getStatusCode(), 'Nessuna pro-forma prima della conferma');
        $admin->post('/admin/ordini/1001/conferma');
        $order = $app->db->one('SELECT * FROM orders WHERE id = 1001');
        self::assertSame('confirmed', $order['status']);
        self::assertSame('PFW-2026-0001', $order['receipt_number']);
        $mail = $app->mail->sentTo('cliente@example.com');
        $confirmation = end($mail);
        self::assertStringContainsString('confermato', $confirmation->subject);
        self::assertSame('proforma-PFW-2026-0001.pdf', $confirmation->attachments[0]['name']);
        self::assertStringStartsWith('%PDF', $confirmation->attachments[0]['content']);

        $pdf = $customer->get('/account/ordini/1001/proforma.pdf');
        self::assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        $admin->post('/admin/ordini/1001/conferma');
        self::assertSame('PFW-2026-0001', $app->db->value('SELECT receipt_number FROM orders WHERE id = 1001'), 'Una conferma sola');

        $other = $app->http('198.51.100.201');
        $app->createCustomer(['email' => 'altro@example.com']);
        $other->loginCustomer('altro@example.com');
        self::assertSame(404, $other->get('/account/ordini/1001')->getStatusCode(), 'Ordini di altri: non trovati');
        self::assertSame(404, $other->get('/account/ordini/1001/proforma.pdf')->getStatusCode());
    }

    public function testReceiptNumbersFollowTheYearlySeries(): void
    {
        [$app, , $admin] = $this->withOrder();
        $admin->post('/admin/ordini/1001/conferma');
        $app->db->run("UPDATE orders SET status = 'pending', receipt_number = NULL WHERE id = 1001");
        $admin->post('/admin/ordini/1001/conferma');
        self::assertSame('PFW-2026-0002', $app->db->value('SELECT receipt_number FROM orders WHERE id = 1001'));
        $app->clock->set('2027-01-02T10:00:00Z');
        $app->db->run("UPDATE orders SET status = 'pending', receipt_number = NULL WHERE id = 1001");
        $admin = $app->http('198.51.100.202');
        $admin->loginAdmin();
        $admin->post('/admin/ordini/1001/conferma');
        self::assertSame('PFW-2027-0001', $app->db->value('SELECT receipt_number FROM orders WHERE id = 1001'));
    }

    public function testEuCustomerWithOrdinaryLinesNeedsVies(): void
    {
        [$app, , $admin] = $this->withOrder(
            ['email' => 'k@example.com', 'country_code' => 'DE', 'vat_number' => 'DE123456789', 'address_zip' => '10115', 'address_province' => ''],
            [90054 => 3, 90052 => 2],
            'ordinary',
        );
        $admin->post('/admin/ordini/1001/conferma');
        self::assertSame('pending', $app->db->value('SELECT status FROM orders WHERE id = 1001'));
        $admin->post('/admin/ordini/1001/conferma', ['vies' => '1']);
        self::assertSame('confirmed', $app->db->value('SELECT status FROM orders WHERE id = 1001'));
        self::assertNotNull($app->db->value('SELECT vies_checked_at FROM orders WHERE id = 1001'));
    }

    public function testRealignRemovesExtendedPairsFirstAndRecomputesTotals(): void
    {
        [$app, , $admin] = $this->withOrder();
        $line = $app->db->one("SELECT * FROM order_lines WHERE order_id = 1001 AND size_eu = '36'");
        self::assertSame([2, 1], [$line['qty_immediate'], $line['qty_extended']]);
        $other = $app->db->one("SELECT * FROM order_lines WHERE order_id = 1001 AND size_eu = '38'");
        $before = count($app->mail->sentTo('cliente@example.com'));
        $admin->post('/admin/ordini/1001/riallinea', ['qty' => [$line['id'] => '2', $other['id'] => '2'], 'notify' => '1']);
        $line = $app->db->one('SELECT * FROM order_lines WHERE id = :id', ['id' => $line['id']]);
        self::assertSame([2, 0], [$line['qty_immediate'], $line['qty_extended']], 'Tolta prima la paia in stock esteso');
        self::assertNull($line['unit_price_extended_cents']);
        $order = $app->db->one('SELECT * FROM orders WHERE id = 1001');
        self::assertSame(4, $order['pairs']);
        self::assertSame(30000, $order['subtotal_margin_cents']);
        self::assertSame(30000 + 1500 + 330, $order['total_cents']);
        self::assertSame(4 * 7137, $order['expected_cost_cents']);
        self::assertCount($before + 1, $app->mail->sentTo('cliente@example.com'), 'Email con il nuovo importo');

        $admin->post('/admin/ordini/1001/riallinea', ['qty' => [$line['id'] => '0', $other['id'] => '0']]);
        self::assertSame(4, $app->db->int('SELECT pairs FROM orders WHERE id = 1001'), 'Non si può togliere tutto');
    }

    public function testCancelAndAdminPages(): void
    {
        [$app, , $admin] = $this->withOrder();
        $list = (string) $admin->get('/admin/ordini')->getBody();
        self::assertStringContainsString('1001', $list);
        $page = (string) $admin->get('/admin/ordini/1001')->getBody();
        self::assertStringContainsString('71,37', $page, 'Costi visibili in admin');
        self::assertStringContainsString('Differenza spedizione', $page);
        $admin->post('/admin/ordini/1001/annulla');
        self::assertSame('cancelled', $app->db->value('SELECT status FROM orders WHERE id = 1001'));
        self::assertNotNull($app->db->value('SELECT cancelled_at FROM orders WHERE id = 1001'));
        $admin->post('/admin/ordini/1001/conferma');
        self::assertSame('cancelled', $app->db->value('SELECT status FROM orders WHERE id = 1001'));
    }
}
