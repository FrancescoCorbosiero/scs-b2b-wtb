<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/** Orologio fermo, per i test. */
final class FrozenClock extends Clock
{
    private DateTimeImmutable $now;

    public function __construct(string $now = '2026-11-12T09:00:00Z')
    {
        $this->now = (new DateTimeImmutable($now))->setTimezone(new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function set(string $now): void
    {
        $this->now = (new DateTimeImmutable($now))->setTimezone(new DateTimeZone('UTC'));
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify(sprintf('%+d seconds', $seconds));
    }
}
