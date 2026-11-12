<?php

declare(strict_types=1);

// Backup del database a mano (stesso job dello scheduler): php bin/backup.php
use App\Bootstrap;
use App\Jobs\BackupJob;
use App\Jobs\JobRunner;

require __DIR__ . '/../vendor/autoload.php';

$container = Bootstrap::container();
$outcome = $container->get(JobRunner::class)->run($container->get(BackupJob::class), 'cli', true);
echo strtoupper($outcome->status), ': ', $outcome->message, "\n";
exit($outcome->status === 'error' ? 1 : 0);
