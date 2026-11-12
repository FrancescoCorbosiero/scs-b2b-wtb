<?php

declare(strict_types=1);

// Applica le migrazioni nuove di database/migrations/ (solo in avanti).
use App\Bootstrap;
use App\Support\Clock;
use App\Support\Db;
use App\Support\Migrator;

require __DIR__ . '/../vendor/autoload.php';

$container = Bootstrap::container();
$migrator = new Migrator($container->get(Db::class), Bootstrap::root() . '/database/migrations', $container->get(Clock::class));
$done = $migrator->migrate();
echo $done === [] ? "Database già aggiornato.\n" : 'Migrazioni applicate: ' . implode(', ', $done) . "\n";
