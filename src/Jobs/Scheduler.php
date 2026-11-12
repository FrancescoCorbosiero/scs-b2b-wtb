<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Clock;
use App\Support\Db;

/** Ciclo del container "scheduler": lancia i job scaduti secondo il loro intervallo. */
final class Scheduler
{
    /** @param list<Job> $jobs */
    public function __construct(
        private readonly array $jobs,
        private readonly JobRunner $runner,
        private readonly Db $db,
        private readonly Clock $clock,
    ) {
    }

    /** @return list<Job> */
    public function jobs(): array
    {
        return $this->jobs;
    }

    public function job(string $name): ?Job
    {
        foreach ($this->jobs as $job) {
            if ($job->name() === $name) {
                return $job;
            }
        }

        return null;
    }

    public function isDue(Job $job): bool
    {
        $last = $this->db->value(
            "SELECT started_at FROM job_runs WHERE job = :j AND status != 'skipped' ORDER BY id DESC LIMIT 1",
            ['j' => $job->name()],
        );
        if ($last === null) {
            return true;
        }
        $at = Clock::parse((string) $last);

        return $at === null || $at->getTimestamp() + $job->interval() <= $this->clock->timestamp();
    }

    /** Un giro: esegue i job scaduti. @return list<string> nomi eseguiti */
    public function tick(): array
    {
        $ran = [];
        foreach ($this->jobs as $job) {
            if ($this->isDue($job)) {
                $this->runner->run($job);
                $ran[] = $job->name();
            }
        }

        return $ran;
    }

    /** Gira finché qualcuno non chiede di fermarsi (SIGTERM del container). */
    public function loop(StopSignal $signal, int $sleepSeconds = 30): void
    {
        while (!$signal->stopRequested()) {
            $this->tick();
            for ($i = 0; $i < $sleepSeconds; $i++) {
                if ($signal->stopRequested()) {
                    return;
                }
                sleep(1);
            }
        }
    }
}
