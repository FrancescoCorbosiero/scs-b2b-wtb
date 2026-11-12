<?php

declare(strict_types=1);

namespace App\Jobs;

final class JobOutcome
{
    /** @param array<string, int> $counts */
    public function __construct(
        public readonly string $status,
        public readonly array $counts = [],
        public readonly string $message = '',
    ) {
    }
}
