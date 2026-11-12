<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Orologio iniettabile: nel database le date sono testo ISO 8601 in UTC.
 */
class Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function timestamp(): int
    {
        return $this->now()->getTimestamp();
    }

    /** Data e ora correnti nel formato del database (ISO 8601 UTC, es. 2026-11-12T07:00:00Z). */
    public function iso(): string
    {
        return self::toIso($this->now());
    }

    /** Data/ora di N secondi fa (o tra N secondi se negativo) nel formato del database. */
    public function isoAgo(int $seconds): string
    {
        return self::toIso($this->now()->modify(sprintf('%+d seconds', -$seconds)));
    }

    public static function toIso(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function parse(?string $iso): ?DateTimeImmutable
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
