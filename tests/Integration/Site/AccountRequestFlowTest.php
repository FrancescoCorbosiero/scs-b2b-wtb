<?php

declare(strict_types=1);

namespace Tests\Integration\Site;

use App\Accounts\UserRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class AccountRequestFlowTest extends TestCase
{
    /** @return array<string, string> */
    private function form(): array
    {
        return [
            'company' => 'Kicks Berlin GmbH', 'vat_number' => 'DE123456789', 'name' => 'Jonas Weber', 'email' => 'jonas@example.com',
            'phone' => '+49 30 1234567', 'country_code' => 'DE', 'address_street' => 'Torstraße 1', 'address_city' => 'Berlin',
            'address_zip' => '10119', 'address_province' => '', 'locale' => 'en', 'notes' => 'Store in Mitte',
        ];
    }

    public function testRequestApproveAndInvite(): void
    {
        $app = new TestApp();
        $http = $app->http();
        $http->post('/richiedi-accesso', $this->form());
        self::assertSame('/richiedi-accesso/inviata', $http->last->getHeaderLine('Location'));
        self::assertSame(1, $app->db->int("SELECT COUNT(*) FROM account_requests WHERE status = 'pending'"));
        self::assertCount(1, $app->mail->sentTo('info@shoesclothingstore.com'), 'Email all\'admin');
        $confirmation = $app->mail->sentTo('jonas@example.com');
        self::assertCount(1, $confirmation);
        self::assertStringContainsString('We received your request', $confirmation[0]->subject, 'Nella lingua del cliente');

        $http->post('/richiedi-accesso', $this->form());
        self::assertSame('/richiedi-accesso', $http->last->getHeaderLine('Location'), 'Doppione bloccato');

        $admin = $app->http('198.51.100.70');
        $admin->loginAdmin();
        self::assertStringContainsString('Kicks Berlin GmbH', (string) $admin->get('/admin/richieste-profilo')->getBody());
        $id = $app->db->int('SELECT id FROM account_requests');
        $admin->post('/admin/richieste-profilo/' . $id . '/approva');
        $user = $app->get(UserRepository::class)->findByEmail('jonas@example.com');
        self::assertNotNull($user);
        self::assertSame('/admin/clienti/' . $user['id'], $admin->last->getHeaderLine('Location'));
        self::assertSame('DE123456789', $user['vat_number']);
        self::assertSame('approved', $app->db->value('SELECT status FROM account_requests WHERE id = :id', ['id' => $id]));
        $invite = $app->mail->sentTo('jonas@example.com');
        self::assertCount(2, $invite);
        self::assertStringContainsString('/account/imposta-password?token=', end($invite)->text);
    }

    public function testValidationHoneypotRateLimitAndReject(): void
    {
        $app = new TestApp();
        $http = $app->http();
        $bad = $this->form();
        $bad['vat_number'] = '12';
        $bad['email'] = 'no';
        $http->post('/richiedi-accesso', $bad);
        $page = (string) $http->get('/richiedi-accesso')->getBody();
        self::assertStringContainsString('Partita IVA non valida', $page);
        self::assertStringContainsString('Indirizzo email non valido', $page);
        self::assertStringContainsString('Kicks Berlin GmbH', $page);

        $bot = $this->form() + ['website' => 'http://spam.example'];
        $http->post('/richiedi-accesso', $bot);
        self::assertSame('/richiedi-accesso/inviata', $http->last->getHeaderLine('Location'), 'Il bot crede di avercela fatta');
        self::assertSame(0, $app->db->int('SELECT COUNT(*) FROM account_requests'));

        for ($i = 1; $i <= 4; $i++) {
            $form = $this->form();
            $form['email'] = "shop$i@example.com";
            $form['vat_number'] = 'DE12345678' . $i;
            $http->post('/richiedi-accesso', $form);
        }
        self::assertSame(3, $app->db->int('SELECT COUNT(*) FROM account_requests'), 'Massimo 3 richieste all\'ora per IP');

        $admin = $app->http('198.51.100.71');
        $admin->loginAdmin();
        $id = $app->db->int('SELECT MIN(id) FROM account_requests');
        $admin->post('/admin/richieste-profilo/' . $id . '/rifiuta');
        self::assertSame('rejected', $app->db->value('SELECT status FROM account_requests WHERE id = :id', ['id' => $id]));
        self::assertNull($app->get(UserRepository::class)->findByEmail('shop1@example.com'));
    }
}
