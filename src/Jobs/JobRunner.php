<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Clock;
use App\Support\Db;
use Psr\Log\LoggerInterface;

/**
 * Esegue un job con un lock (niente esecuzioni sovrapposte, anche tra scheduler e "Sincronizza ora")
 * e registra ogni esecuzione in job_runs.
 */
final class JobRunner
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $lockDir,
    ) {
    }

    public function run(Job $job, string $trigger = 'scheduler', bool $force = false): JobOutcome
    {
        if (!is_dir($this->lockDir)) {
            mkdir($this->lockDir, 0775, true);
        }
        $lock = fopen($this->lockDir . '/' . $job->name() . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $outcome = new JobOutcome('skipped', [], 'Già in esecuzione.');
            $this->record($job->name(), $trigger, $outcome, $this->clock->iso());

            return $outcome;
        }
        $runId = $this->db->insert('job_runs', [
            'job' => $job->name(), 'started_at' => $this->clock->iso(), 'status' => 'running', 'trigger' => $trigger,
        ]);
        try {
            $outcome = $job->run($force);
        } catch (\Throwable $e) {
            $outcome = new JobOutcome('error', [], mb_substr($e->getMessage(), 0, 1000));
            $this->logger->error('Job non riuscito', ['job' => $job->name(), 'error' => $e->getMessage(), 'class' => $e::class]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->db->update('job_runs', [
            'finished_at' => $this->clock->iso(),
            'status' => $outcome->status,
            'counts' => (string) json_encode($outcome->counts),
            'message' => $outcome->message,
        ], 'id = :id', ['id' => $runId]);

        return $outcome;
    }

    private function record(string $name, string $trigger, JobOutcome $outcome, string $now): void
    {
        $this->db->insert('job_runs', [
            'job' => $name, 'started_at' => $now, 'finished_at' => $now, 'status' => $outcome->status,
            'counts' => (string) json_encode($outcome->counts), 'message' => $outcome->message, 'trigger' => $trigger,
        ]);
    }
}
