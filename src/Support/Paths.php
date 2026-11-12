<?php

declare(strict_types=1);

namespace App\Support;

/** Cartelle di lavoro sotto var/ (database, cache immagini, log, backup, email in sviluppo). */
final class Paths
{
    public function __construct(private readonly string $root)
    {
    }

    public function root(string $sub = ''): string
    {
        return $this->root . ($sub !== '' ? '/' . ltrim($sub, '/') : '');
    }

    public function var(string $sub = ''): string
    {
        return $this->root('var' . ($sub !== '' ? '/' . ltrim($sub, '/') : ''));
    }

    public function ensure(string $dir): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
