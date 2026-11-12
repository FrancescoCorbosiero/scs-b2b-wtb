<?php

declare(strict_types=1);

namespace App\Accounts;

/** Password Argon2id, minimo 10 caratteri (docs/09). */
final class Passwords
{
    public const MIN_LENGTH = 10;

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public static function verify(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            // Tempo simile anche senza hash, per non rivelare quali account esistono.
            static $dummy = null;
            $dummy ??= password_hash('dummy-password', PASSWORD_ARGON2ID);
            password_verify($password, $dummy);

            return false;
        }

        return password_verify($password, $hash);
    }

    /** @return string|null chiave di traduzione dell'errore, o null se va bene */
    public static function policyError(string $password, string $confirm): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'auth.password_too_short';
        }
        if (mb_strlen($password) > 200) {
            return 'auth.password_too_long';
        }
        if (!hash_equals($password, $confirm)) {
            return 'auth.password_mismatch';
        }

        return null;
    }
}
