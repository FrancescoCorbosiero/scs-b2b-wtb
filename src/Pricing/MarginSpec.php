<?php

declare(strict_types=1);

namespace App\Pricing;

/** Come si ricava il prezzo di vendita: percent (punti base), fixed (centesimi), fixed_price (centesimi). */
final class MarginSpec
{
    public const TYPES = ['percent', 'fixed', 'fixed_price'];

    public function __construct(
        public readonly string $type,
        public readonly int $value,
        public readonly ?int $ruleId = null,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Tipo di margine sconosciuto: ' . $type);
        }
        if ($value < 0) {
            throw new \InvalidArgumentException('Margine negativo.');
        }
    }

    /** Margine di default dalle impostazioni: "5.00" percento o euro. */
    public static function fromDefault(string $type, string $decimal): self
    {
        [$int, $dec] = array_pad(explode('.', $decimal), 2, '0');
        $hundredths = (int) $int * 100 + (int) str_pad(substr($dec, 0, 2), 2, '0');

        // percent: 5.00% = 500 punti base; fixed: 5.00 € = 500 centesimi.
        return new self($type === 'fixed' ? 'fixed' : 'percent', $hundredths);
    }
}
