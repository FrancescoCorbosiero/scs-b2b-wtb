<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Support\Clock;
use App\Support\Db;

/** Copia consistente del database (VACUUM INTO) ogni giorno; tiene BACKUP_KEEP_DAYS giorni (docs/02). */
final class BackupJob implements Job
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly string $directory,
        private readonly int $keepDays,
    ) {
    }

    public function name(): string
    {
        return 'backup';
    }

    public function interval(): int
    {
        return 86400;
    }

    public function run(bool $force = false): JobOutcome
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
        $file = $this->directory . '/db-' . $this->clock->now()->format('Ymd-His') . '.sqlite';
        $this->db->run('VACUUM INTO :file', ['file' => $file]);
        $removed = 0;
        $cutoff = $this->clock->timestamp() - $this->keepDays * 86400;
        foreach (glob($this->directory . '/db-*.sqlite') ?: [] as $old) {
            if (filemtime($old) !== false && filemtime($old) < $cutoff) {
                @unlink($old);
                $removed++;
            }
        }

        return new JobOutcome('ok', ['bytes' => (int) filesize($file), 'removed' => $removed], 'Backup: ' . basename($file));
    }
}
