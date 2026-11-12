<?php

declare(strict_types=1);

namespace App\Support;

/** Token CSRF per sessione, confronto a tempo costante (docs/09). */
final class Csrf
{
    public const FIELD = '_csrf';
    public const HEADER = 'X-CSRF-Token';

    public static function token(Session $session): string
    {
        $token = $session->get('_csrf');
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $session->set('_csrf', $token);
        }

        return $token;
    }

    public static function isValid(Session $session, ?string $submitted): bool
    {
        $token = $session->get('_csrf');

        return is_string($token) && $token !== '' && is_string($submitted) && hash_equals($token, $submitted);
    }
}
