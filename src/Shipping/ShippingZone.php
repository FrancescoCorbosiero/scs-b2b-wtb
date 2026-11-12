<?php

declare(strict_types=1);

namespace App\Shipping;

/** Una zona di spedizione al cliente (valori dal database). */
final class ShippingZone
{
    public function __construct(
        public readonly string $code,
        public readonly string $nameIt,
        public readonly string $nameEn,
        public readonly int $feeCents,
        public readonly ?int $freeFromItems,
        public readonly int $transitMin,
        public readonly int $transitMax,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['zone'],
            (string) $row['name_it'],
            (string) $row['name_en'],
            (int) $row['fee_cents'],
            $row['free_from_items'] === null ? null : (int) $row['free_from_items'],
            (int) $row['transit_days_min'],
            (int) $row['transit_days_max'],
        );
    }

    public function name(string $locale): string
    {
        return $locale === 'en' ? $this->nameEn : $this->nameIt;
    }
}
