<?php

declare(strict_types=1);

namespace Tests\Integration\Orders;

use App\Supplier\Wtb\Http\TransportException;
use App\Supplier\Wtb\Http\TransportResponse;
use PHPUnit\Framework\TestCase;
use Tests\Support\OrderHelpers;
use Tests\Support\TestApp;

/** Ordine al fornitore (docs/06 § Ordine a WTB): trigger, payload, idempotenza col reference, errori, controlli. */
final class SupplierOrdersTest extends TestCase
{
    use OrderHelpers;

    private const ADMIN_EMAIL = 'info@shoesclothingstore.com';

    public function testConfirmCreatesASimulatedSupplierOrderInFixtureMode(): void
    {
        $app = new TestApp();
        [, $admin] = $this->placeOrder($app);
        $admin->post('/admin/ordini/1001/conferma');

        $row = $app->db->one('SELECT * FROM supplier_orders WHERE order_id = 1001');
        self::assertNotNull($row);
        self::assertSame('simulated', $row['status']);
        self::assertSame('fixture', $row['mode']);
        self::assertSame('SCSW-1001-1', $row['reference']);
        self::assertSame(1, $row['attempt']);
        self::assertSame('bank_transfer', $row['payment_method']);
        self::assertGreaterThan(0, $row['total_cents']);
        self::assertSame($row['total_cents'], $row['expected_total_cents'], 'Totale uguale al preventivo');

        // Payload: una riga per variante + regime, mai prezzi né note del cliente; email nostra nel ship_to.
        $payload = json_decode((string) $row['request_payload'], true);
        self::assertSame('SCSW-1001-1', $payload['reference']);
        self::assertSame('', $payload['notes']);
        self::assertSame(self::ADMIN_EMAIL, $payload['ship_to']['email']);
        self::assertSame('IT', $payload['ship_to']['country']);
        foreach ($payload['lines'] as $line) {
            self::assertSame(['variant_id', 'vat_regime', 'quantity'], array_keys($line));
        }
        self::assertSame(5, array_sum(array_column($payload['lines'], 'quantity')));
        self::assertStringNotContainsString('Consegna al mattino', (string) $row['request_payload']);
        self::assertStringNotContainsString('price', (string) $row['request_payload']);
        $sent = json_decode((string) end($app->fixtures()->requests)['body'], true);
        self::assertSame($payload, $sent, 'Il payload salvato è quello inviato');

        $mails = $app->mail->sentTo(self::ADMIN_EMAIL);
        self::assertStringContainsString('simulato', end($mails)->subject);
        $page = (string) $admin->get('/admin/ordini/1001')->getBody();
        self::assertStringContainsString('SCSW-1001-1', $page);
        self::assertStringContainsString('Simulato', $page);

        // Un secondo tentativo non crea un altro ordine.
        $admin->post('/admin/ordini/1001/fornitore');
        self::assertSame(1, $app->db->int('SELECT COUNT(*) FROM supplier_orders'));
    }

    public function testRetryAfterNetworkErrorsReusesTheSameReference(): void
    {
        $app = new TestApp(['WTB_ORDER_TRIGGER' => 'manual']);
        [, $admin] = $this->placeOrder($app);
        $admin->post('/admin/ordini/1001/conferma');
        self::assertSame(0, $app->db->int('SELECT COUNT(*) FROM supplier_orders'), 'Trigger manual: niente ordine alla conferma');

        for ($i = 0; $i < 3; $i++) {
            $app->fixtures()->queue('POST', '/orders/', new TransportException('Timeout di rete'));
        }
        $admin->post('/admin/ordini/1001/fornitore');
        $row = $app->db->one('SELECT * FROM supplier_orders WHERE order_id = 1001');
        self::assertSame('error', $row['status']);
        self::assertSame('SCSW-1001-1', $row['reference']);
        self::assertStringContainsString('ritentare in sicurezza', (string) $row['last_error']);
        $posts = array_filter($app->fixtures()->requests, static fn (array $r): bool => $r['method'] === 'POST' && str_contains($r['url'], '/orders/'));
        self::assertCount(3, $posts, 'Tre tentativi');
        foreach ($posts as $post) {
            self::assertSame('SCSW-1001-1', json_decode((string) $post['body'], true)['reference'], 'Sempre lo stesso reference');
        }
        $mails = $app->mail->sentTo(self::ADMIN_EMAIL);
        self::assertStringContainsString('NON creato', end($mails)->subject);
        self::assertStringContainsString('Ritenta', (string) $admin->get('/admin/ordini/1001')->getBody());

        $admin->post('/admin/ordini/1001/fornitore');
        self::assertSame(1, $app->db->int('SELECT COUNT(*) FROM supplier_orders'), 'Nessun doppione');
        $row = $app->db->one('SELECT * FROM supplier_orders WHERE order_id = 1001');
        self::assertSame('simulated', $row['status']);
        self::assertSame('SCSW-1001-1', $row['reference']);
        self::assertSame('', $row['last_error']);
    }

    public function testRejectionAndMissingPairsAreCertainFailures(): void
    {
        $app = new TestApp(['WTB_ORDER_TRIGGER' => 'manual']);
        [, $admin] = $this->placeOrder($app);

        $app->fixtures()->queue('POST', '/quote/', TransportResponse::json(400, ['stock' => ['ZX1001-100 EU 36 (margin): only 1 available.']]));
        $before = count($app->fixtures()->requests);
        $admin->post('/admin/ordini/1001/fornitore');
        $row = $app->db->one('SELECT * FROM supplier_orders WHERE order_id = 1001');
        self::assertSame('error', $row['status']);
        self::assertStringContainsString('only 1 available', (string) $row['last_error']);
        self::assertStringContainsString('Riallinea', (string) $row['last_error']);
        $after = array_slice($app->fixtures()->requests, $before);
        self::assertSame([], array_filter($after, static fn (array $r): bool => str_contains($r['url'], '/orders/')), 'Paia mancanti: nessuna creazione');

        $app->fixtures()->queue('POST', '/orders/', TransportResponse::json(400, ['ship_to' => 'Missing field: street']));
        $admin->post('/admin/ordini/1001/fornitore');
        $row = $app->db->one('SELECT * FROM supplier_orders WHERE order_id = 1001');
        self::assertSame('error', $row['status']);
        self::assertSame('SCSW-1001-1', $row['reference'], 'Stesso tentativo dopo un errore');
        self::assertStringContainsString('Rifiutato dal fornitore', (string) $row['last_error']);
        self::assertSame(1, $app->db->int('SELECT COUNT(*) FROM supplier_orders'));
    }

    public function testKillSwitchStopsEveryOrderEvenManual(): void
    {
        $app = new TestApp(['WTB_ORDERS_ENABLED' => '0']);
        [, $admin] = $this->placeOrder($app);
        $admin->post('/admin/ordini/1001/conferma');
        self::assertSame('confirmed', $app->db->value('SELECT status FROM orders WHERE id = 1001'), 'La conferma resta valida');
        $admin->post('/admin/ordini/1001/fornitore');
        self::assertSame(0, $app->db->int('SELECT COUNT(*) FROM supplier_orders'));
        self::assertSame([], array_filter($app->fixtures()->requests, static fn (array $r): bool => str_contains($r['url'], '/orders/')));
        $dashboard = (string) $admin->get('/admin')->getBody();
        self::assertStringContainsString('Ordini al fornitore spenti', $dashboard);
        self::assertStringContainsString('Confermati senza ordine al fornitore', $dashboard);
    }

    public function testModeMismatchAndSpendingCapRefuse(): void
    {
        $app = new TestApp(['WTB_ORDER_TRIGGER' => 'manual', 'WTB_MAX_ORDER_EUR' => '100']);
        [, $admin] = $this->placeOrder($app);
        $admin->post('/admin/ordini/1001/fornitore');
        self::assertSame(0, $app->db->int('SELECT COUNT(*) FROM supplier_orders'), 'Oltre il tetto: nessun ordine');
        self::assertStringContainsString('WTB_MAX_ORDER_EUR', (string) $admin->get('/admin/ordini/1001')->getBody());

        $app->db->run("UPDATE orders SET mode = 'test' WHERE id = 1001");
        $service = $app->get(\App\Orders\SupplierOrderService::class);
        $result = $service->create(1001, 'test');
        self::assertSame('refused', $result['status']);
        self::assertStringContainsString('non si mescolano', $result['message']);
    }

    public function testNewAttemptOnlyAfterCancellation(): void
    {
        $app = new TestApp();
        [, $admin] = $this->placeOrder($app);
        $admin->post('/admin/ordini/1001/conferma');
        $app->db->run("UPDATE supplier_orders SET status = 'cancelled' WHERE order_id = 1001");
        $admin->post('/admin/ordini/1001/fornitore');
        self::assertSame(['SCSW-1001-1', 'SCSW-1001-2'], $app->db->column('SELECT reference FROM supplier_orders ORDER BY id'));
        self::assertSame(2, $app->db->int("SELECT attempt FROM supplier_orders WHERE reference = 'SCSW-1001-2'"));
    }

    public function testOnRequestTriggerCreatesTheOrderAfterTheCheckoutResponse(): void
    {
        $app = new TestApp(['WTB_ORDER_TRIGGER' => 'on_request', 'WTB_SHIP_TO_EMAIL' => 'customer']);
        [, $admin] = $this->placeOrder($app);
        $row = $app->db->one('SELECT * FROM supplier_orders WHERE order_id = 1001');
        self::assertNotNull($row, 'Creato dopo la risposta al cliente');
        self::assertSame('checkout', $row['created_by']);
        self::assertSame('cliente@example.com', json_decode((string) $row['request_payload'], true)['ship_to']['email']);
        self::assertSame('pending', $app->db->value('SELECT status FROM orders WHERE id = 1001'));

        $admin->post('/admin/ordini/1001/conferma');
        self::assertSame(1, $app->db->int('SELECT COUNT(*) FROM supplier_orders'), 'La conferma non ne crea un altro');
    }

    public function testRealignIsBlockedOnceASupplierOrderExists(): void
    {
        $app = new TestApp();
        [, $admin] = $this->placeOrder($app);
        $admin->post('/admin/ordini/1001/conferma');
        $line = $app->db->int('SELECT id FROM order_lines WHERE order_id = 1001 ORDER BY id LIMIT 1');
        $admin->post('/admin/ordini/1001/riallinea', ['qty' => [$line => '1']]);
        self::assertSame(5, $app->db->int('SELECT pairs FROM orders WHERE id = 1001'));
    }
}
