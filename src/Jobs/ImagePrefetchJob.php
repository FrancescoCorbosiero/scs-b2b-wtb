<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Catalog\ImageService;

/** Scarica in anticipo le immagini dei prodotti nuovi (docs/03 § Immagini). */
final class ImagePrefetchJob implements Job
{
    public function __construct(private readonly ImageService $images)
    {
    }

    public function name(): string
    {
        return 'image_prefetch';
    }

    public function interval(): int
    {
        return 600;
    }

    public function run(bool $force = false): JobOutcome
    {
        $done = $this->images->prefetch($force ? 1000 : 150);

        return new JobOutcome('ok', ['images' => $done], $done . ' immagini in cache.');
    }
}
