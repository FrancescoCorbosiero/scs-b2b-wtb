<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Catalog\StockSync;

final class StockSyncJob implements Job
{
    public function __construct(
        private readonly StockSync $sync,
        private readonly int $interval,
    ) {
    }

    public function name(): string
    {
        return 'stock_sync';
    }

    public function interval(): int
    {
        return $this->interval;
    }

    public function run(bool $force = false): JobOutcome
    {
        $result = $this->sync->run($force);

        return new JobOutcome($result->status, $result->counts, ProductsSyncJob::withWarnings($result->message, $result->warnings));
    }
}
