<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Support\Clock;
use App\Support\Db;

/** Lockout: 5 tentativi falliti in 15 minuti per (IP, ambito) ⇒ blocco temporaneo (docs/09). */
final class LoginThrottle
{
    public const MAX_FAILURES = 5;
    public const WINDOW = 900;

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
    ) {
    }

    public function isLocked(string $ip, string $scope): bool
    {
        return $this->failures($ip, $scope) >= self::MAX_FAILURES;
    }

    public function failures(string $ip, string $scope): int
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = :ip AND scope = :s AND success = 0 AND attempted_at > :since',
            ['ip' => $ip, 's' => $scope, 'since' => $this->clock->isoAgo(self::WINDOW)],
        );
    }

    public function record(string $ip, string $scope, bool $success): void
    {
        $this->db->insert('login_attempts', [
            'ip_address' => $ip,
            'scope' => $scope,
            'attempted_at' => $this->clock->iso(),
            'success' => $success ? 1 : 0,
        ]);
    }

    public function purgeOlderThan(int $seconds): int
    {
        return $this->db->run('DELETE FROM login_attempts WHERE attempted_at < :t', ['t' => $this->clock->isoAgo($seconds)])->rowCount();
    }
}
