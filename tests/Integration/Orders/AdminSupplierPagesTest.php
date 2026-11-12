<?php

declare(strict_types=1);

namespace Tests\Integration\Orders;

use PHPUnit\Framework\TestCase;
use Tests\Support\OrderHelpers;
use Tests\Support\TestApp;

/** Pagine admin della M5: ordini fornitore, istruzioni di pagamento, "Da pagare", spedizione a mano. */
final class AdminSupplierPagesTest extends TestCase
{
    use OrderHelpers;

    public function testSupplierOrdersListAndLiveDetail(): void
    {
        $app = new TestApp();
        $admin = $app->http('198.51.100.200');
        $admin->loginAdmin();
        $list = $admin->get('/admin/ordini-fornitore');
        self::assertSame(200, $list->getStatusCode());
        $html = (string) $list->getBody();
        self::assertStringContainsString('WTB/2026/11/9042', $html);
        self::assertStringContainsString('SCSW-1-1', $html);
        self::assertStringContainsString('Non nostro', $html);

        $detail = (string) $admin->get('/admin/ordini-fornitore/9042')->getBody();
        self::assertStringContainsString('71,37', $detail, 'Costi visibili in admin');
        self::assertStringContainsString('IT60X0542811101000000123456', $detail);
        self::assertStringContainsString('data-copy', $detail);
        self::assertSame(404, $admin->get('/admin/ordini-fornitore/1')->getStatusCode());
        self::assertSame(303, $app->http('198.51.100.9')->get('/admin/ordini-fornitore')->getStatusCode(), 'Solo admin');
    }

    public function testPaymentInstructionsAndToPayList(): void
    {
        $app = new TestApp();
        [, $admin] = $this->placeOrder($app);
        $admin->post('/admin/ordini/1001/conferma');
        $dashboard = (string) $admin->get('/admin')->getBody();
        self::assertStringContainsString('Niente da pagare al fornitore', $dashboard, 'I simulati non si pagano');

        // Portato avanti con "Simula evento": ricevuto e non pagato ⇒ da pagare, con IBAN, causale e importo.
        $admin->post('/admin/webhook/simula', ['event' => 'order.created', 'reference' => 'SCSW-1001-1']);
        $dashboard = (string) $admin->get('/admin')->getBody();
        self::assertStringContainsString('SCSW-1001-1', $dashboard);
        self::assertStringContainsString('data-copy="IT60X0542811101000000123456"', $dashboard);
        $page = (string) $admin->get('/admin/ordini/1001')->getBody();
        self::assertStringContainsString('Istruzioni di pagamento al fornitore', $page);
        self::assertStringContainsString('Causale', $page);
        self::assertStringContainsString('Da pagare', $page);

        $admin->post('/admin/webhook/simula', ['event' => 'order.paid', 'reference' => 'SCSW-1001-1']);
        self::assertStringContainsString('Niente da pagare al fornitore', (string) $admin->get('/admin')->getBody());
    }

    public function testManualShippingEmailsTheCustomer(): void
    {
        $app = new TestApp(['WTB_ORDER_TRIGGER' => 'manual']);
        [$customer, $admin] = $this->placeOrder($app);
        $admin->post('/admin/ordini/1001/spedito', ['carrier' => 'BRT', 'number' => 'DEMO1234567', 'url' => '']);
        self::assertSame('pending', $app->db->value('SELECT status FROM orders WHERE id = 1001'), 'Solo ordini confermati');

        $admin->post('/admin/ordini/1001/conferma');
        $admin->post('/admin/ordini/1001/spedito', ['carrier' => 'BRT', 'number' => 'x', 'url' => '']);
        self::assertSame('confirmed', $app->db->value('SELECT status FROM orders WHERE id = 1001'), 'Numero non valido');
        $before = count($app->mail->sentTo('cliente@example.com'));
        $admin->post('/admin/ordini/1001/spedito', ['carrier' => 'BRT', 'number' => 'DEMO1234567', 'url' => 'https://www.brt.it/it/tracking?parcelID=DEMO1234567']);
        self::assertSame('shipped', $app->db->value('SELECT status FROM orders WHERE id = 1001'));
        $mails = $app->mail->sentTo('cliente@example.com');
        self::assertCount($before + 1, $mails);
        self::assertStringContainsString('https://www.brt.it/it/tracking?parcelID=DEMO1234567', end($mails)->html);

        // Secondo numero: email solo per quello; un link non di corriere non arriva al cliente.
        $admin->post('/admin/ordini/1001/spedito', ['carrier' => 'Altro', 'number' => 'ZZ-998877', 'url' => 'https://tracking.example.org/ZZ-998877']);
        $mails = $app->mail->sentTo('cliente@example.com');
        self::assertCount($before + 2, $mails);
        $last = end($mails);
        self::assertStringContainsString('ZZ-998877', $last->html);
        self::assertStringNotContainsString('tracking.example.org', $last->html . $last->text);
        self::assertStringNotContainsString('DEMO1234567', $last->html);
        $page = (string) $customer->get('/account/ordini/1001')->getBody();
        self::assertStringContainsString('DEMO1234567', $page);
        self::assertStringContainsString('ZZ-998877', $page);
        self::assertStringNotContainsString('tracking.example.org', $page);
    }
}
