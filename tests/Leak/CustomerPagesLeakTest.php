<?php

declare(strict_types=1);

namespace Tests\Leak;

use PHPUnit\Framework\TestCase;
use Tests\Support\LeakAssertions;
use Tests\Support\TestApp;

/**
 * Test "cost leak" (docs/02, docs/09): non si spegne mai. Ogni nuova uscita verso il cliente
 * (pagina, JSON, frammento, export, email, PDF) va aggiunta qui o nei test vicini.
 */
final class CustomerPagesLeakTest extends TestCase
{
    use LeakAssertions;

    public function testPublicAndAccountPages(): void
    {
        $app = new TestApp();
        $app->syncCatalog();
        self::assertNotEmpty($this->costNeedles($app), 'Servono costi riconoscibili nel database');
        $app->createCustomer();

        $visitor = $app->http();
        foreach (['/', '/login', '/password-dimenticata', '/account/imposta-password?token=x', '/non-esiste'] as $path) {
            $this->assertResponseHasNoLeak($app, $visitor->get($path), $path);
        }

        $customer = $app->http('198.51.100.60');
        $customer->loginCustomer();
        foreach (['/account', '/'] as $path) {
            $response = $customer->get($path);
            self::assertSame(200, $response->getStatusCode(), $path);
            $this->assertResponseHasNoLeak($app, $response, $path . ' (cliente)');
        }
        $productId = $app->db->int("SELECT id FROM products WHERE sku = 'ZX1001-100'");
        $this->assertResponseHasNoLeak($app, $customer->get('/img/' . $productId . '/0-400.jpg'), 'immagine');
    }

    public function testCustomerEmails(): void
    {
        $app = new TestApp();
        $app->syncCatalog();
        $admin = $app->http();
        $admin->loginAdmin();
        $admin->post('/admin/clienti', [
            'company' => 'Leak Test S.r.l.', 'vat_number' => '01234567897', 'name' => 'Anna', 'email' => 'anna@example.com',
            'phone' => '+39 333 1111111', 'country_code' => 'IT', 'address_street' => 'Via 1', 'address_city' => 'Roma',
            'address_zip' => '00100', 'address_province' => 'RM', 'locale' => 'en',
        ]);
        $app->http()->post('/password-dimenticata', ['email' => 'anna@example.com']);
        self::assertNotEmpty($app->mail->sent);
        foreach ($app->mail->sent as $email) {
            $this->assertNoLeak($app, $email->subject . "\n" . $email->html . "\n" . $email->text, 'email "' . $email->subject . '"');
        }
    }
}
