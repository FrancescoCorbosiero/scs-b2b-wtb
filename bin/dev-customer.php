<?php

declare(strict_types=1);

// Solo sviluppo: crea (o aggiorna) un cliente attivo con password, per provare l'area riservata
// senza passare dall'email d'invito. Uso: php bin/dev-customer.php email@example.com 'password' [IT|DE|...]
use App\Accounts\UserRepository;
use App\Bootstrap;
use App\Support\Config;

require __DIR__ . '/../vendor/autoload.php';

$config = Config::load(Bootstrap::root());
if (!$config->isDevelopment()) {
    fwrite(STDERR, "Disponibile solo con APP_ENV=development.\n");
    exit(1);
}
[$email, $password, $country] = [$argv[1] ?? 'demo@example.com', $argv[2] ?? 'demo-password', strtoupper($argv[3] ?? 'IT')];
if (strlen($password) < 10) {
    fwrite(STDERR, "La password deve avere almeno 10 caratteri.\n");
    exit(1);
}
$users = Bootstrap::container($config)->get(UserRepository::class);
$user = $users->findByEmail($email);
$vat = ['IT' => 'IT01234567897', 'DE' => 'DE123456789', 'FR' => 'FRXX123456789', 'GB' => 'GB123456789', 'CH' => 'CHE123456789MWST'][$country] ?? $country . '12345678';
$id = $user === null ? $users->create([
    'email' => $email, 'name' => 'Cliente Demo', 'company' => 'Demo Sneakers S.r.l.', 'vat_number' => $vat,
    'phone' => '+39 000 0000000', 'address_street' => 'Via Demo 1', 'address_city' => $country === 'IT' ? 'Milano' : 'Demo City',
    'address_zip' => $country === 'IT' ? '20100' : '10115', 'address_province' => $country === 'IT' ? 'MI' : '',
    'country_code' => $country, 'locale' => $country === 'IT' ? 'it' : 'en',
]) : (int) $user['id'];
$users->setPassword($id, $password);
$users->setActive($id, true);
echo "Cliente pronto: $email (id $id, paese $country)\n";
