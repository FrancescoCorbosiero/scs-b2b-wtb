<?php

declare(strict_types=1);

namespace App\Supplier\Model;

/** Zona di spedizione del fornitore (costi nostri, solo admin). */
final class SupplierZone
{
    /** @param list<string> $countries */
    public function __construct(
        public readonly string $name,
        public readonly array $countries,
        public readonly int $firstPieceCents,
        public readonly int $eachAdditionalCents,
        public readonly int $failedDeliveryCents,
        public readonly bool $dutiesPaidByRecipient,
    ) {
    }

    /** Costo di zona per N paia in pronta consegna (M0: le paia estese non contano). */
    public function costFor(int $pieces): int
    {
        return $pieces <= 0 ? 0 : $this->firstPieceCents + ($pieces - 1) * $this->eachAdditionalCents;
    }
}
