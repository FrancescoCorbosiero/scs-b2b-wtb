<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Limiti di frequenza su form e azioni (docs/09): conta i "colpi" per (bucket, soggetto) in una finestra.
 */
final class RateLimiter
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
    ) {
    }

    public function tooMany(string $bucket, string $subject, int $max, int $windowSeconds): bool
    {
        return $this->count($bucket, $subject, $windowSeconds) >= $max;
    }

    public function count(string $bucket, string $subject, int $windowSeconds): int
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM rate_limit_hits WHERE bucket = :b AND subject = :s AND hit_at > :since',
            ['b' => $bucket, 's' => $subject, 'since' => $this->clock->isoAgo($windowSeconds)],
        );
    }

    public function hit(string $bucket, string $subject): void
    {
        $this->db->insert('rate_limit_hits', ['bucket' => $bucket, 'subject' => $subject, 'hit_at' => $this->clock->iso()]);
    }

    public function purgeOlderThan(int $seconds): int
    {
        return $this->db->run('DELETE FROM rate_limit_hits WHERE hit_at < :t', ['t' => $this->clock->isoAgo($seconds)])->rowCount();
    }
}
