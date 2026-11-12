<?php

declare(strict_types=1);

// Genera l'hash Argon2id per ADMIN_PASSWORD_HASH: php bin/hash-password.php 'la-password'
// (senza argomento la chiede senza mostrarla). L'hash va nel .env del server, mai in git.
$password = $argv[1] ?? null;
if ($password === null) {
    fwrite(STDOUT, 'Password admin: ');
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty -echo');
    }
    $password = trim((string) fgets(STDIN));
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty echo');
    }
    fwrite(STDOUT, "\n");
}
if (strlen($password) < 12) {
    fwrite(STDERR, "Usa almeno 12 caratteri per la password admin.\n");
    exit(1);
}
echo password_hash($password, PASSWORD_ARGON2ID), "\n";
