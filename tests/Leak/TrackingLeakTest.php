<?php

declare(strict_types=1);

namespace Tests\Leak;

use App\Supplier\Wtb\WebhookVerifier;
use PHPUnit\Framework\TestCase;
use Tests\Support\LeakAssertions;
use Tests\Support\OrderHelpers;
use Tests\Support\TestApp;

/**
 * Spedizione e tracking verso il cliente (regole d'oro 1 e 2): email e pagine senza costi, senza il nome
 * del fornitore, senza link che non siano di un corriere, anche se il fornitore li manda.
 */
final class TrackingLeakTest extends TestCase
{
    use LeakAssertions;
    use OrderHelpers;

    public function testShippingEmailsAndPagesNeverShowSupplierOrCosts(): void
    {
        $app = new TestApp();
        [$customer, $admin] = $this->placeOrder($app, [90011 => 3, 90014 => 2, 90071 => 1]);
        $admin->post('/admin/ordini/1001/conferma');
        $data = json_decode((string) $app->db->value("SELECT response_payload FROM supplier_orders WHERE reference = 'SCSW-1001-1'"), true);
        $data['status'] = 'shipped';
        $data['tracking'] = [
            ['carrier' => 'WTB Express', 'number' => 'DEMO000123456789', 'url' => 'https://b2b.wtbsnkrs.it/track/DEMO000123456789'],
            ['carrier' => 'BRT', 'number' => 'DEMO000987654321', 'url' => 'https://www.brt.it/it/tracking?parcelID=DEMO000987654321'],
        ];
        $body = (string) json_encode(['id' => 1, 'event' => 'order.shipped', 'created_at' => '2026-11-12T10:00:00+01:00', 'data' => $data]);
        $response = $app->http('192.0.2.50')->postRaw('/webhooks/wtb', $body, [
            'Content-Type' => 'application/json',
            'X-WTB-Delivery' => 'leak-1',
            'X-WTB-Signature' => WebhookVerifier::sign('whsec_test_secret_for_tests', $app->clock->timestamp(), $body),
        ]);
        self::assertSame(200, $response->getStatusCode());
        $admin->post('/admin/ordini/1001/spedito', ['carrier' => 'BRT', 'number' => 'DEMO555', 'url' => 'https://www.brt.it/x']);

        foreach (['/account/ordini', '/account/ordini/1001'] as $path) {
            $page = $customer->get($path);
            self::assertSame(200, $page->getStatusCode());
            $this->assertResponseHasNoLeak($app, $page, $path);
        }
        $mails = $app->mail->sentTo('cliente@example.com');
        self::assertGreaterThanOrEqual(4, count($mails), 'Richiesta, conferma, spedizione, nuovo numero');
        foreach ($mails as $email) {
            $this->assertNoLeak($app, $email->subject . $email->html . $email->text, 'email "' . $email->subject . '"');
        }
        $shipped = $mails[2];
        self::assertStringContainsString('DEMO000123456789', $shipped->html, 'Il numero arriva comunque');
        self::assertStringContainsString('https://www.brt.it/it/tracking?parcelID=DEMO000987654321', $shipped->html);
    }
}
