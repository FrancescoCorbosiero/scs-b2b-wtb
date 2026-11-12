<?php

declare(strict_types=1);

namespace App\Catalog;

/** Esito di un sync: stato, conteggi per job_runs e avvisi sulle righe scartate. */
final class SyncResult
{
    /**
     * @param array<string, int> $counts
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $status,
        public readonly array $counts,
        public readonly string $message,
        public readonly array $warnings = [],
    ) {
    }
}
