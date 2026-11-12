<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Support\Clock;
use App\Support\Db;

/**
 * Token monouso per invito e reset (docs/09): a DB solo lo sha256, il chiaro sta solo nel link.
 * Un nuovo token invalida i precedenti dello stesso utente.
 */
final class TokenService
{
    public const INVITE_TTL = 72 * 3600;
    public const RESET_TTL = 2 * 3600;

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
    ) {
    }

    public function issue(int $userId, string $purpose): string
    {
        if (!in_array($purpose, ['invite', 'reset'], true)) {
            throw new \InvalidArgumentException('Scopo del token non valido.');
        }
        $token = bin2hex(random_bytes(32));
        $now = $this->clock->iso();
        $ttl = $purpose === 'invite' ? self::INVITE_TTL : self::RESET_TTL;
        $this->db->transaction(function (Db $db) use ($userId, $purpose, $token, $now, $ttl): void {
            $db->run('UPDATE user_tokens SET used_at = :now WHERE user_id = :u AND used_at IS NULL', ['now' => $now, 'u' => $userId]);
            $db->insert('user_tokens', [
                'user_id' => $userId,
                'token_hash' => hash('sha256', $token),
                'purpose' => $purpose,
                'expires_at' => Clock::toIso($this->clock->now()->modify('+' . $ttl . ' seconds')),
                'created_at' => $now,
            ]);
        });

        return $token;
    }

    /**
     * Token valido (non usato, non scaduto)? Restituisce la riga del token o null.
     *
     * @return array<string, mixed>|null
     */
    public function peek(string $token): ?array
    {
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            return null;
        }
        $row = $this->db->one(
            'SELECT * FROM user_tokens WHERE token_hash = :h AND used_at IS NULL AND expires_at > :now',
            ['h' => hash('sha256', $token), 'now' => $this->clock->iso()],
        );

        return $row;
    }

    /** Segna il token come usato; restituisce l'id utente o null se non valido. */
    public function consume(string $token): ?int
    {
        $row = $this->peek($token);
        if ($row === null) {
            return null;
        }
        $updated = $this->db->run(
            'UPDATE user_tokens SET used_at = :now WHERE id = :id AND used_at IS NULL',
            ['now' => $this->clock->iso(), 'id' => $row['id']],
        )->rowCount();

        return $updated === 1 ? (int) $row['user_id'] : null;
    }

    public function countRecent(int $userId, string $purpose, int $windowSeconds): int
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM user_tokens WHERE user_id = :u AND purpose = :p AND created_at > :since',
            ['u' => $userId, 'p' => $purpose, 'since' => $this->clock->isoAgo($windowSeconds)],
        );
    }

    public function purgeExpired(): int
    {
        return $this->db->run(
            'DELETE FROM user_tokens WHERE expires_at < :t',
            ['t' => $this->clock->isoAgo(30 * 86400)],
        )->rowCount();
    }
}
