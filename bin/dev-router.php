<?php

declare(strict_types=1);

// Router per lo sviluppo: php -S 127.0.0.1:8080 -t public bin/dev-router.php
// Serve i file statici di public/ (assets, robots.txt, favicon) e manda il resto a public/index.php.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . '/../public' . $path;
if (is_string($path) && $path !== '/' && is_file($file) && !str_ends_with($path, '.php')) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/../public/index.php';
