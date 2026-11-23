<?php

declare(strict_types=1);

namespace App\Shipping;

use App\Support\Db;

/**
 * Paesi per i menu a tendina, in modo "smart" (D-020): solo i paesi serviti,
 * in testa i più usati, preselezionato l'ultimo usato.
 *
 * Il cliente vede in testa solo i paesi dei SUOI ordini (mai quelli degli altri);
 * l'admin quelli di tutti gli ordini.
 */
final class CountryChoices
{
    /** Quanti paesi "più usati" in testa al menu. */
    public const TOP = 5;

    public function __construct(private readonly Db $db, private readonly CountryRepository $countries)
    {
    }

    /**
     * @param int|null $userId cliente (solo i suoi ordini) o null per l'admin (tutti gli ordini)
     * @param list<string> $preferred paesi da tenere comunque in testa (es. quello di fatturazione)
     *
     * @return array{top: array<string, string>, rest: array<string, string>} codice => nome
     */
    public function groups(string $locale, ?int $userId, array $preferred = []): array
    {
        $all = $this->countries->servedOptions($locale);
        $codes = [];
        foreach ([...$this->used($userId), ...$preferred, 'IT'] as $code) {
            $code = strtoupper($code);
            if (isset($all[$code]) && !in_array($code, $codes, true) && count($codes) < self::TOP) {
                $codes[] = $code;
            }
        }
        $top = [];
        foreach ($codes as $code) {
            $top[$code] = $all[$code];
        }

        return ['top' => $top, 'rest' => array_diff_key($all, $top)];
    }

    /** Ultimo paese di destinazione usato (cliente o, con null, ordini dell'admin), se ancora servito. */
    public function lastUsed(?int $userId): ?string
    {
        $code = $userId === null
            ? $this->db->value("SELECT ship_country FROM orders WHERE source = 'admin' ORDER BY id DESC LIMIT 1")
            : $this->db->value('SELECT ship_country FROM orders WHERE user_id = :u ORDER BY id DESC LIMIT 1', ['u' => $userId]);

        return is_string($code) && $this->countries->isServed($code) ? strtoupper($code) : null;
    }

    /** @return list<string> paesi di destinazione, dal più usato */
    private function used(?int $userId): array
    {
        $rows = $userId === null
            ? $this->db->all('SELECT ship_country AS c, COUNT(*) AS n, MAX(id) AS last FROM orders GROUP BY ship_country ORDER BY n DESC, last DESC LIMIT ' . self::TOP)
            : $this->db->all('SELECT ship_country AS c, COUNT(*) AS n, MAX(id) AS last FROM orders WHERE user_id = :u GROUP BY ship_country ORDER BY n DESC, last DESC LIMIT ' . self::TOP, ['u' => $userId]);

        return array_map(static fn (array $r): string => (string) $r['c'], $rows);
    }
}
