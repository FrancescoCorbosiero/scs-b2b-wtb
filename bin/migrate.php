<?php

declare(strict_types=1);

// Applica le migrazioni nuove di database/migrations/ (solo in avanti), poi porta nelle impostazioni
// i valori rimasti nel .env per le voci spostate nell'admin (D-021, una tantum).
use App\Bootstrap;
use App\Settings\EnvImport;
use App\Settings\SettingsService;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Db;
use App\Support\Migrator;

require __DIR__ . '/../vendor/autoload.php';

$container = Bootstrap::container();
$migrator = new Migrator($container->get(Db::class), Bootstrap::root() . '/database/migrations', $container->get(Clock::class));
$done = $migrator->migrate();
echo $done === [] ? "Database già aggiornato.\n" : 'Migrazioni applicate: ' . implode(', ', $done) . "\n";
foreach ((new EnvImport($container->get(Config::class), $container->get(SettingsService::class)))->run() as $line) {
    echo $line . "\n";
}
