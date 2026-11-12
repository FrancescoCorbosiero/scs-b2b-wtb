<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Support\Session;

/** Area admin: password dedicata (ADMIN_PASSWORD_HASH, Argon2id), sessione distinta (docs/09). */
final class AdminAuth
{
    public function __construct(
        private readonly string $passwordHash,
        private readonly LoginThrottle $throttle,
    ) {
    }

    public function isConfigured(): bool
    {
        return str_starts_with($this->passwordHash, '$argon2');
    }

    /** @return 'ok'|'locked'|'invalid'|'not_configured' */
    public function attempt(string $password, string $ip): string
    {
        if (!$this->isConfigured()) {
            return 'not_configured';
        }
        if ($this->throttle->isLocked($ip, 'admin')) {
            return 'locked';
        }
        $ok = password_verify($password, $this->passwordHash);
        $this->throttle->record($ip, 'admin', $ok);

        return $ok ? 'ok' : 'invalid';
    }

    public function login(Session $session, string $name): void
    {
        $session->destroy();
        $session->set('admin', true);
        $session->set('admin_name', $name === '' ? 'admin' : mb_substr($name, 0, 60));
    }

    public function logout(Session $session): void
    {
        $session->destroy();
    }

    public function isLoggedIn(Session $session): bool
    {
        return $session->get('admin') === true;
    }
}
