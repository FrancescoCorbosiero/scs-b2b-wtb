<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Http\AppFactory;
use App\Support\Deferred;

require __DIR__ . '/../vendor/autoload.php';

$container = Bootstrap::container();
$app = AppFactory::create($container);
$app->run();

// Lavori da fare dopo aver risposto (es. email dei webhook): la risposta è già partita.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
$container->get(Deferred::class)->run();
