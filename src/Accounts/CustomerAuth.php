<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Support\Session;

/** Login dei clienti: email + password, lockout, sessione rigenerata (docs/09). */
final class CustomerAuth
{
    public const SESSION_KEY = 'customer_id';

    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginThrottle $throttle,
    ) {
    }

    /**
     * @return array{status: 'ok'|'locked'|'invalid', user?: array<string, mixed>}
     */
    public function attempt(string $email, string $password, string $ip): array
    {
        if ($this->throttle->isLocked($ip, 'user')) {
            return ['status' => 'locked'];
        }
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            Passwords::verify($password, null);
            $this->throttle->record($ip, 'user', false);

            return ['status' => 'invalid'];
        }
        $ok = Passwords::verify($password, $user['password_hash'] === null ? null : (string) $user['password_hash'])
            && (int) $user['is_active'] === 1;
        $this->throttle->record($ip, 'user', $ok);
        if (!$ok) {
            return ['status' => 'invalid'];
        }
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_ARGON2ID)) {
            $this->users->setPassword((int) $user['id'], $password);
        }

        return ['status' => 'ok', 'user' => $user];
    }

    public function login(Session $session, int $userId): void
    {
        $locale = $session->get('locale');
        $cart = $session->get('cart');
        $session->destroy();
        $session->set(self::SESSION_KEY, $userId);
        if (is_string($locale)) {
            $session->set('locale', $locale);
        }
        if (is_array($cart)) {
            $session->set('cart', $cart);
        }
        $this->users->touchLogin($userId);
    }

    public function logout(Session $session): void
    {
        $locale = $session->get('locale');
        $session->destroy();
        if (is_string($locale)) {
            $session->set('locale', $locale);
        }
    }

    /**
     * Cliente della sessione, se esiste ed è attivo; altrimenti la sessione viene chiusa.
     *
     * @return array<string, mixed>|null
     */
    public function current(Session $session): ?array
    {
        $id = $session->get(self::SESSION_KEY);
        if (!is_int($id)) {
            return null;
        }
        $user = $this->users->find($id);
        if ($user === null || (int) $user['is_active'] !== 1 || $user['password_hash'] === null) {
            $this->logout($session);

            return null;
        }

        return UserRepository::safe($user);
    }
}
