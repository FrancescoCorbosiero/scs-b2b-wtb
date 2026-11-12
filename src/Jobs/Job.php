<?php

declare(strict_types=1);

namespace App\Jobs;

/** Un job periodico dello scheduler (docs/02 § Scheduler e job). */
interface Job
{
    public function name(): string;

    /** Intervallo tra due esecuzioni, in secondi. */
    public function interval(): int;

    public function run(bool $force = false): JobOutcome;
}
