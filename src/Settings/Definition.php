<?php

declare(strict_types=1);

namespace App\Settings;

/**
 * Una manopola del titolare: chiave, tipo, default, etichetta, gruppo e validazione.
 *
 * Tipi: int, money (centesimi), decimal (stringa "5.00"), bool, enum, string, text, list (elenco di valori).
 */
final class Definition
{
    public const TYPES = ['int', 'money', 'decimal', 'bool', 'enum', 'string', 'text', 'list'];

    /**
     * @param array<string, string> $options valori ammessi per gli enum (valore => etichetta)
     * @param (callable(mixed): ?string)|null $validator restituisce un messaggio d'errore o null
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly mixed $default,
        public readonly string $label,
        public readonly string $group,
        public readonly string $help = '',
        public readonly array $options = [],
        public readonly ?int $min = null,
        public readonly ?int $max = null,
        private readonly mixed $validator = null,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Tipo di impostazione sconosciuto: ' . $type);
        }
        if ($type === 'enum' && $options === []) {
            throw new \InvalidArgumentException('Enum senza opzioni: ' . $key);
        }
    }

    /**
     * Converte il valore scritto nel modulo nel valore tipizzato, oppure lancia InvalidSetting.
     */
    public function parseInput(string $input): mixed
    {
        $input = trim($input);
        $value = match ($this->type) {
            'int' => $this->parseInt($input),
            'money' => $this->parseMoney($input),
            'decimal' => $this->parseDecimal($input),
            'bool' => in_array(strtolower($input), ['1', 'true', 'on', 'yes', 'si', 'sì'], true),
            'enum' => $this->parseEnum($input),
            'string' => $this->parseString($input, false),
            'text' => $this->parseString($input, true),
            'list' => $this->parseList($input),
            default => throw new InvalidSetting('Tipo non gestito.'),
        };
        $this->check($value);

        return $value;
    }

    /** Valore salvato nel database (testo) → valore tipizzato; se non è più valido torna il default. */
    public function decode(string $stored): mixed
    {
        try {
            $value = match ($this->type) {
                'int', 'money' => preg_match('/^-?\d+$/', $stored) === 1 ? (int) $stored : throw new InvalidSetting('x'),
                'bool' => $stored === '1',
                'list' => array_values(array_filter(array_map('strval', (array) json_decode($stored, true)))),
                default => $stored,
            };
            $this->check($value);

            return $value;
        } catch (InvalidSetting) {
            return $this->default;
        }
    }

    /** Valore tipizzato → testo per il database. */
    public function encode(mixed $value): string
    {
        return match ($this->type) {
            'bool' => $value ? '1' : '0',
            'list' => (string) json_encode(array_values((array) $value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }

    /** Valore tipizzato → testo per il campo del modulo. */
    public function formatInput(mixed $value): string
    {
        return match ($this->type) {
            'money' => \App\Support\Money::formatInput((int) $value),
            'bool' => $value ? '1' : '0',
            'list' => implode("\n", (array) $value),
            'decimal' => str_replace('.', ',', (string) $value),
            default => (string) $value,
        };
    }

    public function check(mixed $value): void
    {
        if (in_array($this->type, ['int', 'money'], true)) {
            if ($this->min !== null && $value < $this->min) {
                throw new InvalidSetting(sprintf('Il valore minimo è %s.', $this->type === 'money' ? \App\Support\Money::format($this->min) : $this->min));
            }
            if ($this->max !== null && $value > $this->max) {
                throw new InvalidSetting(sprintf('Il valore massimo è %s.', $this->type === 'money' ? \App\Support\Money::format($this->max) : $this->max));
            }
        }
        if ($this->type === 'enum' && !array_key_exists((string) $value, $this->options)) {
            throw new InvalidSetting('Valore non ammesso.');
        }
        if ($this->validator !== null) {
            $error = ($this->validator)($value);
            if ($error !== null) {
                throw new InvalidSetting($error);
            }
        }
    }

    private function parseInt(string $input): int
    {
        if (preg_match('/^-?\d+$/', $input) !== 1) {
            throw new InvalidSetting('Serve un numero intero.');
        }

        return (int) $input;
    }

    private function parseMoney(string $input): int
    {
        $cents = \App\Support\Money::parseInput($input);
        if ($cents === null) {
            throw new InvalidSetting('Serve un importo in euro, es. 15,00.');
        }

        return $cents;
    }

    private function parseDecimal(string $input): string
    {
        $normalized = str_replace(',', '.', $input);
        if (preg_match('/^\d+(\.\d{1,2})?$/', $normalized) !== 1) {
            throw new InvalidSetting('Serve un numero con al massimo due decimali, es. 5 o 7,5.');
        }
        [$int, $dec] = array_pad(explode('.', $normalized), 2, '');
        $value = ltrim($int, '0') === '' ? '0' : ltrim($int, '0');

        return $value . '.' . str_pad($dec, 2, '0');
    }

    private function parseEnum(string $input): string
    {
        if (!array_key_exists($input, $this->options)) {
            throw new InvalidSetting('Valore non ammesso.');
        }

        return $input;
    }

    private function parseString(string $input, bool $multiline): string
    {
        if (!$multiline && preg_match('/[\r\n]/', $input) === 1) {
            throw new InvalidSetting('Il testo deve stare su una riga.');
        }
        if (mb_strlen($input) > ($multiline ? 4000 : 500)) {
            throw new InvalidSetting('Testo troppo lungo.');
        }

        return $input;
    }

    /** @return list<string> */
    private function parseList(string $input): array
    {
        $items = preg_split('/[\s,;]+/', strtolower($input)) ?: [];

        return array_values(array_unique(array_filter($items, static fn (string $v): bool => $v !== '')));
    }
}
