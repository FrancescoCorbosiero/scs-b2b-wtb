<?php

declare(strict_types=1);

namespace App\Jobs;

/** Richiesta di arresto dello scheduler (segnale del container). */
interface StopSignal
{
    /** @phpstan-impure */
    public function stopRequested(): bool;
}
