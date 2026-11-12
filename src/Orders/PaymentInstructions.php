<?php

declare(strict_types=1);

namespace App\Orders;

/**
 * Istruzioni di pagamento del fornitore (forma libera, M0: in prova arrivano null). Si mostrano tutte le chiavi;
 * IBAN, causale e importo si riconoscono per evidenziarli e per i bottoni "copia" (docs/06 § Ordine a WTB punto 6).
 */
final class PaymentInstructions
{
    private const KINDS = [
        'iban' => ['iban', 'account_iban', 'bank_iban'],
        'reason' => ['reason', 'causale', 'reference', 'description', 'payment_reference', 'remittance'],
        'amount' => ['amount', 'total', 'importo', 'amount_due'],
        'beneficiary' => ['beneficiary', 'holder', 'account_holder', 'intestatario', 'name'],
        'bic' => ['bic', 'swift', 'bic_swift'],
        'bank' => ['bank', 'bank_name', 'banca'],
    ];

    /** @return list<array{key: string, kind: string, value: string, highlight: bool}> */
    public static function fromJson(mixed $json): array
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($data) ? self::fromArray($data) : [];
    }

    /**
     * @param array<mixed> $data
     * @return list<array{key: string, kind: string, value: string, highlight: bool}>
     */
    public static function fromArray(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $name = $prefix . (string) $key;
            if (is_array($value)) {
                array_push($out, ...self::fromArray($value, $name . '.'));
                continue;
            }
            if (!is_scalar($value) && $value !== null) {
                continue;
            }
            $kind = self::kind((string) $key);
            $out[] = [
                'key' => mb_substr($name, 0, 60),
                'kind' => $kind,
                'value' => mb_substr(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value, 0, 300),
                'highlight' => in_array($kind, ['iban', 'reason', 'amount'], true),
            ];
        }

        return $out;
    }

    private static function kind(string $key): string
    {
        $key = strtolower($key);
        foreach (self::KINDS as $kind => $names) {
            if (in_array($key, $names, true)) {
                return $kind;
            }
        }

        return 'other';
    }
}
