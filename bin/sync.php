<?php

declare(strict_types=1);

// Sync del catalogo da riga di comando: php bin/sync.php products|stock|images [--force]
// --force: "products" ignora la soglia di calo dei prodotti; "stock" rielabora anche se lo stock è invariato.
use App\Bootstrap;
use App\Jobs\ImagePrefetchJob;
use App\Jobs\JobRunner;
use App\Jobs\ProductsSyncJob;
use App\Jobs\StockSyncJob;

require __DIR__ . '/../vendor/autoload.php';

$what = $argv[1] ?? '';
$force = in_array('--force', $argv, true);
$jobs = ['products' => ProductsSyncJob::class, 'stock' => StockSyncJob::class, 'images' => ImagePrefetchJob::class];
if (!isset($jobs[$what])) {
    fwrite(STDERR, "Uso: php bin/sync.php products|stock|images [--force]\n");
    exit(2);
}
$container = Bootstrap::container();
$outcome = $container->get(JobRunner::class)->run($container->get($jobs[$what]), 'cli', $force);
echo strtoupper($outcome->status), ': ', $outcome->message, "\n";
if ($outcome->counts !== []) {
    echo json_encode($outcome->counts), "\n";
}
exit($outcome->status === 'error' ? 1 : 0);
