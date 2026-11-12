<?php

declare(strict_types=1);

namespace App\Pricing;

/** Una fascia di un'unità vendibile: pronta consegna o stock esteso, con disponibilità e prezzo di vendita. */
final class Tier
{
    public function __construct(
        public readonly string $stockType,
        public readonly int $available,
        public readonly ?int $unitPriceCents,
        public readonly string $shipsInDays,
    ) {
    }
}
