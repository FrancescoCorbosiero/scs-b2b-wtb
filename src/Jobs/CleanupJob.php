<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Accounts\LoginThrottle;
use App\Accounts\TokenService;
use App\Catalog\ImageService;
use App\Support\Clock;
use App\Support\Db;
use App\Support\RateLimiter;
use App\Support\SessionStore;

/** Pulizia giornaliera: sessioni scadute, tentativi di login, token vecchi, storico job, cache immagini orfane. */
final class CleanupJob implements Job
{
    /** @param list<callable(): int> $extra pulizie aggiunte da altri moduli (es. eventi webhook) */
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly SessionStore $sessions,
        private readonly LoginThrottle $logins,
        private readonly RateLimiter $limiter,
        private readonly TokenService $tokens,
        private readonly ImageService $images,
        private readonly array $extra = [],
    ) {
    }

    public function name(): string
    {
        return 'cleanup';
    }

    public function interval(): int
    {
        return 86400;
    }

    public function run(bool $force = false): JobOutcome
    {
        $counts = [
            'sessions' => $this->sessions->purgeExpired(),
            'login_attempts' => $this->logins->purgeOlderThan(30 * 86400),
            'rate_limits' => $this->limiter->purgeOlderThan(2 * 86400),
            'tokens' => $this->tokens->purgeExpired(),
            'job_runs' => $this->db->run('DELETE FROM job_runs WHERE started_at < :t', ['t' => $this->clock->isoAgo(90 * 86400)])->rowCount(),
            'image_dirs' => $this->images->purgeOrphans(),
        ];
        foreach ($this->extra as $i => $cleaner) {
            $counts['extra_' . $i] = $cleaner();
        }

        return new JobOutcome('ok', $counts, 'Pulizia completata.');
    }
}
