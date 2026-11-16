<?php

declare(strict_types=1);

// Cancella gli ordini nati in modalità fixture/test (con righe, ordini al fornitore simulati ed eventi webhook
// collegati o simulati). Mai gli ordini live. Senza --yes mostra solo cosa cancellerebbe; con --yes fa prima
// un backup del database in var/backups/.
//   php bin/purge-test-orders.php          # anteprima
//   php bin/purge-test-orders.php --yes    # backup + cancellazione
use App\Bootstrap;
use App\Support\Config;
use App\Support\Db;

require __DIR__ . '/../vendor/autoload.php';

$container = Bootstrap::container();
/** @var Db $db */
$db = $container->get(Db::class);
/** @var Config $config */
$config = $container->get(Config::class);

$modes = "('fixture', 'test')";
$counts = [
    'ordini' => $db->int("SELECT COUNT(*) FROM orders WHERE mode IN $modes"),
    'righe' => $db->int("SELECT COUNT(*) FROM order_lines WHERE order_id IN (SELECT id FROM orders WHERE mode IN $modes)"),
    'ordini al fornitore' => $db->int("SELECT COUNT(*) FROM supplier_orders WHERE mode IN $modes"),
    'eventi webhook' => $db->int(
        "SELECT COUNT(*) FROM webhook_events WHERE simulated = 1 OR supplier_order_id IN (SELECT id FROM supplier_orders WHERE mode IN $modes)",
    ),
];
$live = $db->int("SELECT COUNT(*) FROM orders WHERE mode = 'live'");

echo "Da cancellare (modalità fixture e test):\n";
foreach ($counts as $label => $n) {
    echo sprintf("  %-20s %d\n", $label, $n);
}
echo "Ordini live (non si toccano): $live\n";

if (!in_array('--yes', $argv, true)) {
    echo "\nAnteprima: niente cancellato. Rilancia con --yes per cancellare (prima fa un backup).\n";
    exit(0);
}
if (array_sum($counts) === 0) {
    echo "Niente da cancellare.\n";
    exit(0);
}

$dir = $config->varPath('backups');
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$backup = $dir . '/pre-purge-' . gmdate('Ymd-His') . '.sqlite';
$db->run('VACUUM INTO :f', ['f' => $backup]);
echo "Backup: $backup\n";

$db->transaction(static function (Db $db) use ($modes): void {
    $db->run("DELETE FROM webhook_events WHERE simulated = 1 OR supplier_order_id IN (SELECT id FROM supplier_orders WHERE mode IN $modes)");
    $db->run("DELETE FROM supplier_orders WHERE mode IN $modes");
    $db->run("DELETE FROM order_lines WHERE order_id IN (SELECT id FROM orders WHERE mode IN $modes)");
    $db->run("DELETE FROM orders WHERE mode IN $modes");
});
echo "Fatto: ordini di prova cancellati.\n";
