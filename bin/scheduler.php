<?php

declare(strict_types=1);

// Ciclo dei job periodici (container "scheduler"): php bin/scheduler.php [--once]
use App\Bootstrap;
use App\Jobs\Scheduler;

require __DIR__ . '/../vendor/autoload.php';

$scheduler = Bootstrap::container()->get(Scheduler::class);
if (in_array('--once', $argv, true)) {
    $ran = $scheduler->tick();
    echo $ran === [] ? "Nessun job da eseguire.\n" : 'Eseguiti: ' . implode(', ', $ran) . "\n";
    exit(0);
}
$signal = new class () implements App\Jobs\StopSignal {
    public bool $stop = false;

    public function stopRequested(): bool
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        return $this->stop;
    }
};
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use ($signal): void {
        $signal->stop = true;
    });
    pcntl_signal(SIGINT, static function () use ($signal): void {
        $signal->stop = true;
    });
}
echo "Scheduler avviato.\n";
$scheduler->loop($signal);
echo "Scheduler fermato.\n";
