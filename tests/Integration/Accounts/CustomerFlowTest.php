<?php

declare(strict_types=1);

namespace Tests\Integration\Accounts;

use App\Accounts\UserRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class CustomerFlowTest extends TestCase
{
    /** @return array<string, string> */
    private function customerForm(): array
    {
        return [
            'company' => 'Neg Sneakers S.r.l.', 'vat_number' => '01234567897', 'name' => 'Lucia Bianchi',
            'email' => 'Lucia@Example.com', 'phone' => '+39 333 000111', 'country_code' => 'IT',
            'address_street' => 'Corso Italia 5', 'address_city' => 'Bari', 'address_zip' => '70121',
            'address_province' => 'ba', 'locale' => 'it',
        ];
    }

    private function linkFromLastEmail(TestApp $app): string
    {
        $email = end($app->mail->sent);
        self::assertNotFalse($email);
        self::assertSame(1, preg_match('#(/account/imposta-password\?token=[a-f0-9]{64})#', $email->text, $m));

        return $m[1];
    }

    public function testInviteSetPasswordLoginAndProfile(): void
    {
        $app = new TestApp();
        $admin = $app->http();
        $admin->loginAdmin();
        $admin->post('/admin/clienti', $this->customerForm());
        self::assertMatchesRegularExpression('#^/admin/clienti/\d+$#', $admin->last->getHeaderLine('Location'));

        $user = $app->get(UserRepository::class)->findByEmail('lucia@example.com');
        self::assertNotNull($user);
        self::assertSame('IT01234567897', $user['vat_number']);
        self::assertSame('BA', $user['address_province']);
        self::assertNull($user['password_hash'], 'La password non viaggia per email');
        self::assertCount(1, $app->mail->sentTo('lucia@example.com'));
        $link = $this->linkFromLastEmail($app);

        $customer = $app->http('198.51.100.20');
        self::assertSame(200, $customer->get($link)->getStatusCode());
        $token = substr($link, strpos($link, 'token=') + 6);
        $customer->post('/account/imposta-password', ['token' => $token, 'password' => 'corta', 'password_confirm' => 'corta']);
        self::assertStringContainsString('/account/imposta-password', $customer->last->getHeaderLine('Location'));
        $customer->post('/account/imposta-password', ['token' => $token, 'password' => 'una-password-lunga', 'password_confirm' => 'una-password-lunga']);
        self::assertSame('/account', $customer->last->getHeaderLine('Location'));
        self::assertSame(200, $customer->get('/account')->getStatusCode());
        self::assertStringContainsString('Neg Sneakers S.r.l.', $customer->body());

        // Il link vale una volta sola.
        $again = $app->http('198.51.100.21');
        $again->get($link);
        self::assertStringContainsString('Link non valido o scaduto', $again->body());

        // Profilo: l'indirizzo si aggiorna, la P.IVA no.
        $customer->post('/account', [
            'name' => 'Lucia B.', 'phone' => '+39 333 000222', 'address_street' => 'Via Nuova 9', 'address_city' => 'Bari',
            'address_zip' => '70122', 'address_province' => 'BA', 'locale' => 'en', 'vat_number' => 'IT99999999999',
        ]);
        $user = $app->get(UserRepository::class)->findByEmail('lucia@example.com');
        self::assertSame('Via Nuova 9', $user['address_street']);
        self::assertSame('en', $user['locale']);
        self::assertSame('IT01234567897', $user['vat_number']);

        $customer->post('/logout');
        self::assertSame(303, $customer->get('/account')->getStatusCode());
        $customer->loginCustomer('lucia@example.com', 'una-password-lunga');
        self::assertSame(200, $customer->get('/account')->getStatusCode());
    }

    public function testValidationErrorsAndDuplicateEmail(): void
    {
        $app = new TestApp();
        $app->createCustomer(['email' => 'lucia@example.com']);
        $admin = $app->http();
        $admin->loginAdmin();
        $form = $this->customerForm();
        $form['vat_number'] = '123';
        $admin->post('/admin/clienti', $form);
        self::assertSame('/admin/clienti/nuovo', $admin->last->getHeaderLine('Location'));
        $page = (string) $admin->get('/admin/clienti/nuovo')->getBody();
        self::assertStringContainsString('Partita IVA non valida', $page);
        self::assertStringContainsString('Esiste già un profilo con questa email', $page);
        self::assertStringContainsString('Neg Sneakers S.r.l.', $page, 'I valori inseriti vengono ripresentati');
    }

    public function testLoginLockoutAndDeactivation(): void
    {
        $app = new TestApp();
        $id = $app->createCustomer();
        $http = $app->http('198.51.100.30');
        for ($i = 0; $i < 5; $i++) {
            $http->post('/login', ['email' => 'cliente@example.com', 'password' => 'sbagliata']);
        }
        $http->post('/login', ['email' => 'cliente@example.com', 'password' => 'customer-password']);
        self::assertStringStartsWith('/login', $http->last->getHeaderLine('Location'));
        $http->get('/login');
        self::assertStringContainsString('Troppi tentativi', $http->body());

        $other = $app->http('198.51.100.31');
        $other->loginCustomer();
        self::assertSame(200, $other->get('/account')->getStatusCode());

        $app->get(UserRepository::class)->setActive($id, false);
        $response = $other->get('/account');
        self::assertSame(303, $response->getStatusCode(), 'Account disattivato: sessione chiusa alla richiesta successiva');
        self::assertStringStartsWith('/login', $response->getHeaderLine('Location'));
    }

    public function testForgotPasswordIsNeutralAndLimited(): void
    {
        $app = new TestApp();
        $app->createCustomer();
        $http = $app->http();
        $http->post('/password-dimenticata', ['email' => 'nessuno@example.com']);
        self::assertCount(0, $app->mail->sent);
        $http->get('/password-dimenticata');
        $neutral = $http->body();
        self::assertStringContainsString('corrisponde a un profilo attivo', $neutral);

        for ($i = 0; $i < 5; $i++) {
            $http->post('/password-dimenticata', ['email' => 'cliente@example.com']);
        }
        self::assertCount(3, $app->mail->sentTo('cliente@example.com'), 'Massimo 3 link all\'ora per utente');

        $link = $this->linkFromLastEmail($app);
        $token = substr($link, strpos($link, 'token=') + 6);
        $other = $app->http('198.51.100.40');
        $other->post('/account/imposta-password', ['token' => $token, 'password' => 'nuova-password-1', 'password_confirm' => 'nuova-password-1']);
        self::assertSame('/account', $other->last->getHeaderLine('Location'));
        $fresh = $app->http('198.51.100.41');
        $fresh->loginCustomer('cliente@example.com', 'nuova-password-1');
    }
}
