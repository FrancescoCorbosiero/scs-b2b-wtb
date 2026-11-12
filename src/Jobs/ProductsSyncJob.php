<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Catalog\ProductSync;

final class ProductsSyncJob implements Job
{
    public function __construct(
        private readonly ProductSync $sync,
        private readonly int $interval,
    ) {
    }

    public function name(): string
    {
        return 'products_sync';
    }

    public function interval(): int
    {
        return $this->interval;
    }

    public function run(bool $force = false): JobOutcome
    {
        $result = $this->sync->run($force);

        return new JobOutcome($result->status, $result->counts, self::withWarnings($result->message, $result->warnings));
    }

    /** @param list<string> $warnings */
    public static function withWarnings(string $message, array $warnings): string
    {
        if ($warnings === []) {
            return $message;
        }

        return $message . "\nAvvisi (" . count($warnings) . "):\n- " . implode("\n- ", array_slice($warnings, 0, 30));
    }
}
