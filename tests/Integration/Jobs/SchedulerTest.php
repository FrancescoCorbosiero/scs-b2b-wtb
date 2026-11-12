<?php

declare(strict_types=1);

namespace Tests\Integration\Jobs;

use App\Jobs\Job;
use App\Jobs\JobOutcome;
use App\Jobs\JobRunner;
use App\Jobs\Scheduler;
use App\Jobs\BackupJob;
use App\Support\Clock;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\TestApp;

final class SchedulerTest extends TestCase
{
    public function testJobsRunWhenDueAndRecordTheirOutcome(): void
    {
        $app = new TestApp();
        $runner = new JobRunner($app->db, $app->clock, new NullLogger(), sys_get_temp_dir() . '/scs-locks-' . bin2hex(random_bytes(3)));
        $calls = 0;
        $job = new class ($calls) implements Job {
            public function __construct(public int &$calls)
            {
            }

            public function name(): string
            {
                return 'demo';
            }

            public function interval(): int
            {
                return 900;
            }

            public function run(bool $force = false): JobOutcome
            {
                $this->calls++;
                if ($this->calls === 2) {
                    throw new \RuntimeException('boom');
                }

                return new JobOutcome('ok', ['n' => $this->calls], 'fatto');
            }
        };
        $scheduler = new Scheduler([$job], $runner, $app->db, $app->clock);
        self::assertSame(['demo'], $scheduler->tick());
        self::assertSame([], $scheduler->tick(), 'Non ancora scaduto');
        $app->clock->advance(901);
        self::assertSame(['demo'], $scheduler->tick());
        $runs = $app->db->all('SELECT status, message, counts FROM job_runs ORDER BY id');
        self::assertSame('ok', $runs[0]['status']);
        self::assertSame('{"n":1}', $runs[0]['counts']);
        self::assertSame('error', $runs[1]['status'], 'Un\'eccezione diventa una riga in errore, non un crash');
        self::assertSame('boom', $runs[1]['message']);
    }

    public function testLockPreventsOverlappingRuns(): void
    {
        $app = new TestApp();
        $dir = sys_get_temp_dir() . '/scs-locks-' . bin2hex(random_bytes(3));
        mkdir($dir);
        $runner = new JobRunner($app->db, $app->clock, new NullLogger(), $dir);
        $job = $app->get(BackupJob::class);
        $lock = fopen($dir . '/backup.lock', 'c');
        self::assertNotFalse($lock);
        flock($lock, LOCK_EX);
        self::assertSame('skipped', $runner->run($job)->status);
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    public function testBackupMakesAConsistentCopy(): void
    {
        $app = new TestApp();
        $dir = sys_get_temp_dir() . '/scs-bak-' . bin2hex(random_bytes(3));
        $job = new BackupJob($app->db, $app->clock, $dir, 14);
        $outcome = $job->run();
        self::assertSame('ok', $outcome->status);
        $files = glob($dir . '/db-*.sqlite') ?: [];
        self::assertCount(1, $files);
        $copy = new \PDO('sqlite:' . $files[0]);
        self::assertSame(29, (int) $copy->query('SELECT COUNT(*) FROM countries')->fetchColumn());
        foreach ($files as $f) {
            @unlink($f);
        }
        @rmdir($dir);
        self::assertInstanceOf(Clock::class, $app->clock);
    }
}
