<?php

declare(strict_types=1);

namespace Tests\Integration\Orders;

use App\Supplier\Wtb\WebhookVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\HttpClient;
use Tests\Support\OrderHelpers;
use Tests\Support\TestApp;

/** POST /webhooks/wtb (docs/06 § Webhook): firma, duplicati, stati monotoni, ordine sconosciuto, email. */
final class WebhooksTest extends TestCase
{
    use OrderHelpers;

    private const SECRET = 'whsec_test_secret_for_tests';
    private const ADMIN_EMAIL = 'info@shoesclothingstore.com';

    private TestApp $app;
    private HttpClient $hook;
    private HttpClient $customer;

    /** Ordine 1001 confermato, con l'ordine al fornitore simulato SCSW-1001-1. */
    private function boot(array $env = []): HttpClient
    {
        $this->app = new TestApp($env);
        [$this->customer, $admin] = $this->placeOrder($this->app);
        $admin->post('/admin/ordini/1001/conferma');
        self::assertSame('simulated', $this->app->db->value("SELECT status FROM supplier_orders WHERE reference = 'SCSW-1001-1'"));
        $this->hook = $this->app->http('192.0.2.50');

        return $admin;
    }

    /** Dati dell'ordine come li manda il fornitore: la risposta salvata alla creazione, con le modifiche. @return array<string, mixed> */
    private function data(array $changes = []): array
    {
        $raw = json_decode((string) $this->app->db->value("SELECT response_payload FROM supplier_orders WHERE reference = 'SCSW-1001-1'"), true);

        return array_replace($raw, ['id' => 9042, 'number' => 'WTB/2026/11/9042'], $changes);
    }

    private function send(string $event, array $data, string $delivery, ?int $timestamp = null, string $secret = self::SECRET, string $type = 'application/json'): ResponseInterface
    {
        $body = (string) json_encode(['id' => crc32($delivery), 'event' => $event, 'created_at' => '2026-11-12T10:00:00+01:00', 'data' => (object) $data]);

        return $this->hook->postRaw('/webhooks/wtb', $body, [
            'Content-Type' => $type,
            'X-WTB-Event' => $event,
            'X-WTB-Delivery' => $delivery,
            'X-WTB-Signature' => WebhookVerifier::sign($secret, $timestamp ?? $this->app->clock->timestamp(), $body),
        ]);
    }

    private function supplierStatus(): string
    {
        return (string) $this->app->db->value("SELECT status FROM supplier_orders WHERE reference = 'SCSW-1001-1'");
    }

    public function testPingSignatureAndRequestChecks(): void
    {
        $this->boot();
        $response = $this->send('ping', [], 'dlv-1');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $response->getHeader('Set-Cookie'), 'Nessuna sessione');
        self::assertSame('ping', $this->app->db->value("SELECT outcome FROM webhook_events WHERE delivery_id = 'dlv-1'"));

        self::assertSame(401, $this->send('ping', [], 'dlv-2', secret: 'whsec_wrong')->getStatusCode(), 'Segreto sbagliato');
        self::assertSame(401, $this->send('ping', [], 'dlv-3', $this->app->clock->timestamp() - 301)->getStatusCode(), 'Più vecchio di 5 minuti');
        self::assertSame(415, $this->send('ping', [], 'dlv-4', type: 'text/plain')->getStatusCode());
        $unsigned = $this->hook->postRaw('/webhooks/wtb', '{"event":"ping"}', ['Content-Type' => 'application/json', 'X-WTB-Delivery' => 'dlv-5']);
        self::assertSame(401, $unsigned->getStatusCode());
        $huge = $this->hook->postRaw('/webhooks/wtb', str_repeat('x', 1048577), ['Content-Type' => 'application/json']);
        self::assertSame(413, $huge->getStatusCode());
        self::assertSame(1, $this->app->db->int('SELECT COUNT(*) FROM webhook_events'), 'Le richieste rifiutate non toccano il database');
    }

    public function testShippedAndTrackingUpdatesEmailTheCustomerOnce(): void
    {
        $this->boot();
        self::assertSame(200, $this->send('order.confirmed', $this->data(['status' => 'confirmed']), 'dlv-10')->getStatusCode());
        self::assertSame('confirmed', $this->supplierStatus());
        $this->send('order.paid', $this->data(['status' => 'paid', 'is_paid' => true]), 'dlv-11');
        self::assertSame('paid', $this->supplierStatus());
        self::assertSame(1, $this->app->db->int("SELECT is_paid FROM supplier_orders WHERE reference = 'SCSW-1001-1'"));

        $before = count($this->app->mail->sentTo('cliente@example.com'));
        $brt = ['carrier' => 'BRT', 'number' => 'DEMO000123456789', 'url' => 'https://www.brt.it/it/tracking?parcelID=DEMO000123456789'];
        $shipped = $this->data(['status' => 'shipped', 'is_paid' => true, 'tracking' => [$brt]]);
        self::assertSame(200, $this->send('order.shipped', $shipped, 'dlv-12')->getStatusCode());
        self::assertSame('shipped', $this->supplierStatus());
        $order = $this->app->db->one('SELECT status, shipped_at FROM orders WHERE id = 1001');
        self::assertSame('shipped', $order['status']);
        self::assertNotNull($order['shipped_at']);
        $mails = $this->app->mail->sentTo('cliente@example.com');
        self::assertCount($before + 1, $mails);
        $mail = end($mails);
        self::assertStringContainsString('spedito', $mail->subject);
        self::assertStringContainsString('DEMO000123456789', $mail->html);
        self::assertStringContainsString('https://www.brt.it/it/tracking?parcelID=DEMO000123456789', $mail->html);

        // Doppione: 200 senza rielaborare.
        $duplicate = $this->send('order.shipped', $shipped, 'dlv-12');
        self::assertSame(200, $duplicate->getStatusCode());
        self::assertStringContainsString('duplicate', (string) $duplicate->getBody());
        self::assertCount($before + 1, $this->app->mail->sentTo('cliente@example.com'));

        // Nuovo numero: email solo per quello; un link che non è di un corriere non arriva al cliente.
        $other = ['carrier' => 'BRT', 'number' => 'DEMO000987654321', 'url' => 'https://b2b.wtbsnkrs.it/track/DEMO000987654321'];
        $this->send('order.tracking_updated', $this->data(['status' => 'shipped', 'is_paid' => true, 'tracking' => [$brt, $other]]), 'dlv-13');
        $mails = $this->app->mail->sentTo('cliente@example.com');
        self::assertCount($before + 2, $mails);
        $update = end($mails);
        self::assertStringContainsString('DEMO000987654321', $update->html);
        self::assertStringNotContainsString('DEMO000123456789', $update->html, 'Solo il numero nuovo');
        self::assertStringNotContainsString('wtbsnkrs', $update->html . $update->text);

        self::assertSame(2, count((array) json_decode((string) $this->app->db->value("SELECT tracking FROM supplier_orders WHERE reference = 'SCSW-1001-1'"), true)));
        $page = (string) $this->customer->get('/account/ordini/1001')->getBody();
        self::assertStringContainsString('DEMO000123456789', $page);
        self::assertStringContainsString('DEMO000987654321', $page);
        self::assertStringContainsString('https://www.brt.it/it/tracking?parcelID=DEMO000123456789', $page);
        self::assertStringNotContainsString('wtbsnkrs', $page, 'Link del fornitore mai al cliente');
    }

    public function testOutOfOrderEventsNeverGoBackButCancelledAlwaysApplies(): void
    {
        $admin = $this->boot();
        $this->send('order.shipped', $this->data(['status' => 'shipped', 'tracking' => []]), 'dlv-20');
        $this->send('order.confirmed', $this->data(['status' => 'confirmed']), 'dlv-21');
        self::assertSame('shipped', $this->supplierStatus(), 'Evento fuori ordine: lo stato non torna indietro');
        self::assertSame('stale', $this->app->db->value("SELECT outcome FROM webhook_events WHERE delivery_id = 'dlv-21'"));

        $this->send('order.cancelled', $this->data(['status' => 'cancelled']), 'dlv-22');
        self::assertSame('cancelled', $this->supplierStatus());
        $this->send('order.paid', $this->data(['status' => 'paid', 'is_paid' => true]), 'dlv-23');
        self::assertSame('cancelled', $this->supplierStatus(), 'Annullato è definitivo');
        $mails = $this->app->mail->sentTo(self::ADMIN_EMAIL);
        self::assertStringContainsString('ha annullato', end($mails)->subject);
        self::assertSame('shipped', $this->app->db->value('SELECT status FROM orders WHERE id = 1001'), 'Il cliente non viene toccato');
    }

    public function testCancelledWhileOrderIsOpenRaisesAnAlert(): void
    {
        $admin = $this->boot();
        $customerMails = count($this->app->mail->sentTo('cliente@example.com'));
        $this->send('order.cancelled', $this->data(['status' => 'cancelled']), 'dlv-25');
        self::assertSame('confirmed', $this->app->db->value('SELECT status FROM orders WHERE id = 1001'));
        self::assertCount($customerMails, $this->app->mail->sentTo('cliente@example.com'), 'Nessuna email automatica al cliente');
        self::assertStringContainsString('Ordini annullati dal fornitore', (string) $admin->get('/admin')->getBody());
    }

    public function testUnknownOrderIsStoredAnsweredAndAlerted(): void
    {
        $admin = $this->boot();
        $response = $this->send('order.created', $this->data(['reference' => 'PORTALE-77', 'id' => 777]), 'dlv-30');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('unknown_order', $this->app->db->value("SELECT outcome FROM webhook_events WHERE delivery_id = 'dlv-30'"));
        self::assertStringContainsString('Webhook per ordini sconosciuti', (string) $admin->get('/admin')->getBody());
        $list = (string) $admin->get('/admin/webhook?esito=unknown_order')->getBody();
        self::assertStringContainsString('dlv-30', $list);
    }

    public function testUpdatedEventFlagsDifferencesOnce(): void
    {
        $admin = $this->boot();
        $data = $this->data(['status' => 'confirmed']);
        $data['total'] = number_format(((int) $this->app->db->value("SELECT total_cents FROM supplier_orders WHERE reference = 'SCSW-1001-1'") + 500) / 100, 2, '.', '');
        $data['lines'][0]['quantity'] += 1;
        $before = count($this->app->mail->sentTo(self::ADMIN_EMAIL));
        $this->send('order.updated', $data, 'dlv-40');
        $warnings = json_decode((string) $this->app->db->value("SELECT warnings FROM supplier_orders WHERE reference = 'SCSW-1001-1'"), true);
        self::assertCount(2, $warnings, 'Totale e righe');
        $mails = $this->app->mail->sentTo(self::ADMIN_EMAIL);
        self::assertCount($before + 1, $mails);
        self::assertStringContainsString('differenze', end($mails)->subject);
        $this->send('order.updated', $data, 'dlv-41');
        self::assertCount($before + 1, $this->app->mail->sentTo(self::ADMIN_EMAIL), 'Stesse differenze: nessuna nuova email');
        self::assertStringContainsString('Differenze segnalate dal fornitore', (string) $admin->get('/admin')->getBody());
    }

    public function testSignedButUnreadableBodyIsKeptAsError(): void
    {
        $admin = $this->boot();
        $response = $this->send('order.shipped', ['status' => 'teleported', 'reference' => 'SCSW-1001-1'], 'dlv-50');
        self::assertSame(200, $response->getStatusCode(), 'Ritentare non lo aggiusterebbe');
        $row = $this->app->db->one("SELECT * FROM webhook_events WHERE delivery_id = 'dlv-50'");
        self::assertSame('error', $row['outcome']);
        self::assertStringContainsString('Stato', (string) $row['error']);
        self::assertStringContainsString('Webhook non elaborati', (string) $admin->get('/admin')->getBody());
    }

    public function testWithoutSecretNothingIsValid(): void
    {
        $this->boot(['WTB_WEBHOOK_SECRET' => '']);
        self::assertSame(401, $this->send('ping', [], 'dlv-60', secret: '')->getStatusCode());
        self::assertSame(0, $this->app->db->int('SELECT COUNT(*) FROM webhook_events'));
    }

    public function testSimulateEventFromAdmin(): void
    {
        $admin = $this->boot();
        $page = (string) $admin->get('/admin/webhook')->getBody();
        self::assertStringContainsString('Simula evento', $page);
        $admin->post('/admin/webhook/simula', ['event' => 'order.shipped', 'reference' => 'SCSW-1001-1']);
        self::assertSame('shipped', $this->supplierStatus());
        self::assertSame('shipped', $this->app->db->value('SELECT status FROM orders WHERE id = 1001'));
        self::assertSame(1, $this->app->db->int('SELECT simulated FROM webhook_events ORDER BY id DESC LIMIT 1'));
        $mails = $this->app->mail->sentTo('cliente@example.com');
        self::assertStringContainsString('spedito', end($mails)->subject);

        $admin->post('/admin/webhook/simula', ['event' => 'ping', 'reference' => '']);
        self::assertSame('ping', $this->app->db->value('SELECT outcome FROM webhook_events ORDER BY id DESC LIMIT 1'));
        $admin->post('/admin/webhook/simula', ['event' => 'order.exploded', 'reference' => 'SCSW-1001-1']);
        self::assertSame(2, $this->app->db->int('SELECT COUNT(*) FROM webhook_events'), 'Evento sconosciuto: niente');
    }
}
