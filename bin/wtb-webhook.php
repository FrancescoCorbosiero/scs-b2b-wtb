<?php

declare(strict_types=1);

// Evento del fornitore simulato (solo fixture e test, docs/03): firmato con WTB_WEBHOOK_SECRET e fatto passare
// dallo stesso codice dei webhook veri.
//   php bin/wtb-webhook.php <evento> [<ordine>|<reference>] [--url=http://127.0.0.1:8080]
// <ordine> = numero d'ordine (si usa il suo ultimo ordine al fornitore), <reference> = es. SCSW-1001-1.
// Con --url l'evento va via HTTP a <url>/webhooks/wtb (prova anche rotta e middleware), altrimenti in processo.
use App\Bootstrap;
use App\Orders\WebhookProcessor;
use App\Supplier\SupplierOrders;
use App\Supplier\SupplierWebhooks;
use App\Support\Db;
use App\Support\Deferred;

require __DIR__ . '/../vendor/autoload.php';

$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));
$url = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--url=')) {
        $url = rtrim(substr($arg, 6), '/');
    }
}
$event = $args[0] ?? '';
$target = $args[1] ?? '';

$container = Bootstrap::container();
$webhooks = $container->get(SupplierWebhooks::class);
if (!in_array($event, $webhooks->events(), true) || ($event !== 'ping' && $target === '')) {
    fwrite(STDERR, "Uso: php bin/wtb-webhook.php <evento> [<ordine>|<reference>] [--url=http://127.0.0.1:8080]\n");
    fwrite(STDERR, 'Eventi: ' . implode(', ', $webhooks->events()) . "\n");
    exit(2);
}
if ($container->get(SupplierOrders::class)->mode() === 'live') {
    fwrite(STDERR, "In modalità live gli eventi simulati non esistono.\n");
    exit(1);
}
if (!$webhooks->isConfigured()) {
    fwrite(STDERR, "WTB_WEBHOOK_SECRET non impostato nel .env (in sviluppo basta un valore qualsiasi).\n");
    exit(1);
}

$order = null;
if ($event !== 'ping') {
    $db = $container->get(Db::class);
    $row = ctype_digit($target)
        ? $db->one('SELECT reference, response_payload FROM supplier_orders WHERE order_id = :o ORDER BY id DESC LIMIT 1', ['o' => (int) $target])
        : $db->one('SELECT reference, response_payload FROM supplier_orders WHERE reference = :r', ['r' => $target]);
    $reference = $row === null ? (ctype_digit($target) ? 'SIM-' . $target . '-0' : $target) : (string) $row['reference'];
    $decoded = $row !== null && is_string($row['response_payload']) ? json_decode($row['response_payload'], true) : null;
    $order = is_array($decoded) && $decoded !== [] ? $decoded : $webhooks->blankOrder($reference);
    $order['reference'] = $reference;
    echo 'Reference: ', $reference, $row === null ? ' (nessun ordine al fornitore: atteso "ordine sconosciuto")' : '', "\n";
}
$simulated = $webhooks->simulate($event, $order);

if ($url !== '') {
    $headers = [];
    foreach ($simulated['headers'] as $name => $value) {
        $headers[] = $name . ': ' . $value;
    }
    $curl = curl_init($url . '/webhooks/wtb');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $simulated['body'],
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    echo 'HTTP ', $status, ' ', is_string($body) ? $body : '', "\n";
    exit($status >= 200 && $status < 300 ? 0 : 1);
}

$result = $container->get(WebhookProcessor::class)->handle($simulated['headers'], $simulated['body'], true);
$container->get(Deferred::class)->run();
echo $event, ' → ', $result['status'], ' ', $result['outcome'], "\n";
exit($result['status'] < 300 ? 0 : 1);
