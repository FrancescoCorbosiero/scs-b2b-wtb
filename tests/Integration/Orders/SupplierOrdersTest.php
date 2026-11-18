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

        // Payload: una riga per variante + regime, mai prezzi né note del cliente; email e telefono nostri nel ship_to,
        // note fisse con la richiesta di spedire a nome nostro (D-020).
        $payload = json_decode((string) $row['request_payload'], true);
        self::assertSame('SCSW-1001-1', $payload['reference']);
        self::assertSame(\App\Settings\Registry::DEFAULT_SUPPLIER_NOTES, $payload['notes']);
        self::assertSame(self::ADMIN_EMAIL, $payload['ship_to']['email']);
        self::assertSame('+39 392 772 0691', $payload['ship_to']['phone']);
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
        $app = new TestApp();
        $app->setting('supplier.order_trigger', 'manual');
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
        self::assertStringContainsString('riprovare senza rischi', (string) $row['last_error']);
        $posts = array_filter($app->fixtures()->requests, static fn (array $r): bool => $r['method'] === 'POST' && str_contains($r['url'], '/orders/'));
        self::assertCount(3, $posts, 'Tre tentativi');
        foreach ($posts as $post) {
            self::assertSame('SCSW-1001-1', json_decode((string) $post['body'], true)['reference'], 'Sempre lo stesso reference');
        }
        $mails = $app->mail->sentTo(self::ADMIN_EMAIL);
        self::assertStringContainsString('NON arrivato al fornitore', end($mails)->subject);
        self::assertStringContainsString('Riprova a inviare al fornitore', (string) $admin->get('/admin/ordini/1001')->getBody());

        $admin->post('/admin/ordini/1001/fornitore');
        self::assertSame(1, $app->db->int('SELECT COUNT(*) FROM supplier_orders'), 'Nessun doppione');
        $row = $app->db->one('SELECT * FROM supplier_orders WHERE order_id = 1001');
        self::assertSame('simulated', $row['status']);
        self::assertSame('SCSW-1001-1', $row['reference']);
        self::assertSame('', $row['last_error']);
    }

    public function testRejectionAndMissingPairsAreCertainFailures(): void
    {
        $app = new TestApp();
        $app->setting('supplier.order_trigger', 'manual');
        [, $admin] = $this->placeOrder($app);

        $app->fixtures()->queue('POST', '/quote/', TransportResponse::json(400, ['stock' => ['ZX1001-100 EU 36 (margin): only 1 available.']]));
        $before = count($app->fixtures()->requests);
        $admin->post('/admin/ordini/1001/fornitore');
        $row = $app->db->one('SELECT * FROM supplier_orders WHERE order_id = 1001');
        self::assertSame('error', $row['status']);
        self::assertStringContainsString('only 1 available', (string) $row['last_error']);
        self::assertStringContainsString('togli quelle mancanti', (string) $row['last_error']);
        self::assertStringNotContainsString('..', (string) $row['last_error']);
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

    public function testNoPhoneAndEmptyNotesAreLeftOut(): void
    {
        $app = new TestApp();
        $app->setting('supplier.ship_to_phone', 'none');
        $app->setting('supplier.notes', '');
        [, $admin] = $this->placeOrder($app);
        $admin->post('/admin/ordini/1001/conferma');
        $payload = json_decode((string) $app->db->value('SELECT request_payload FROM supplier_orders WHERE order_id = 1001'), true);
        self::assertArrayNotHasKey('phone', $payload['ship_to']);
        self::assertSame('', $payload['notes']);
    }

    public function testUnpaidSupplierOrderRaisesAnAlertAfterTheConfiguredDays(): void
    {
        $app = new TestApp();
        [, $admin] = $this->placeOrder($app);
        $admin->post('/admin/ordini/1001/conferma');
        $app->db->run("UPDATE supplier_orders SET status = 'received', is_paid = 0, created_at = :t WHERE order_id = 1001", ['t' => $app->clock->isoAgo(6 * 86400)]);
        self::assertStringNotContainsString('Ordini al fornitore non ancora pagati', (string) $admin->get('/admin')->getBody());
        $app->db->run('UPDATE supplier_orders SET created_at = :t WHERE order_id = 1001', ['t' => $app->clock->isoAgo(8 * 86400)]);
        self::assertStringContainsString('Ordini al fornitore non ancora pagati', (string) $admin->get('/admin')->getBody());

        $app->setting('supplier.unpaid_days', 0);
        self::assertStringNotContainsString('Ordini al fornitore non ancora pagati', (string) $admin->get('/admin')->getBody());
        $app->setting('supplier.unpaid_days', 7);
        $app->db->run("UPDATE supplier_orders SET is_paid = 1, status = 'paid' WHERE order_id = 1001");
        self::assertStringNotContainsString('Ordini al fornitore non ancora pagati', (string) $admin->get('/admin')->getBody());
    }

    public function testKillSwitchStopsEveryOrderEvenManual(): void
    {
        $app = new TestApp();
        $app->setting('supplier.orders_enabled', false);
        [, $admin] = $this->placeOrder($app);
        $admin->post('/admin/ordini/1001/conferma');
        self::assertSame('confirmed', $app->db->value('SELECT status FROM orders WHERE id = 1001'), 'La conferma resta valida');
        $admin->post('/admin/ordini/1001/fornitore');
        self::assertSame(0, $app->db->int('SELECT COUNT(*) FROM supplier_orders'));
        self::assertSame([], array_filter($app->fixtures()->requests, static fn (array $r): bool => str_contains($r['url'], '/orders/')));
        $dashboard = (string) $admin->get('/admin')->getBody();
        self::assertStringContainsString('Ordini al fornitore spenti', $dashboard);
        self::assertStringContainsString('Pagati dal cliente ma non inviati al fornitore', $dashboard);
    }

    public function testModeMismatchAndSpendingCapRefuse(): void
    {
        $app = new TestApp();
        $app->setting('supplier.order_trigger', 'manual');
        $app->setting('supplier.max_order', 10000);
        [, $admin] = $this->placeOrder($app);
        $admin->post('/admin/ordini/1001/fornitore');
        self::assertSame(0, $app->db->int('SELECT COUNT(*) FROM supplier_orders'), 'Oltre il tetto: nessun ordine');
        self::assertStringContainsString('spesa massima', (string) $admin->get('/admin/ordini/1001')->getBody());

        $app->db->run("UPDATE orders SET mode = 'test' WHERE id = 1001");
        $service = $app->get(\App\Orders\SupplierOrderService::class);
        $result = $service->create(1001, 'test');
        self::assertSame('refused', $result['status']);
        self::assertStringContainsString('restano separati', $result['message']);
        self::assertStringNotContainsString('fixture', strtolower($result['message']), 'Mai i codici delle modalità');
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
        $app = new TestApp();
        $app->setting('supplier.order_trigger', 'on_request');
        $app->setting('supplier.ship_to_email', 'customer');
        $app->setting('supplier.ship_to_phone', 'customer');
        [, $admin] = $this->placeOrder($app);
        $row = $app->db->one('SELECT * FROM supplier_orders WHERE order_id = 1001');
        self::assertNotNull($row, 'Creato dopo la risposta al cliente');
        self::assertSame('checkout', $row['created_by']);
        $shipTo = json_decode((string) $row['request_payload'], true)['ship_to'];
        self::assertSame('cliente@example.com', $shipTo['email']);
        self::assertSame('+39 333 1234567', $shipTo['phone']);
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
