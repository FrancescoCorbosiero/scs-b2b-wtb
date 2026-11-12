<?php

declare(strict_types=1);

namespace App\Orders;

use App\Support\Db;

/** Numerazione delle pro-forma: <prefisso>-<anno>-<NNNN>, serie annuale (D-015). */
final class ReceiptNumberer
{
    public function __construct(
        private readonly Db $db,
        private readonly string $prefix,
    ) {
    }

    public function next(int $year): string
    {
        return $this->db->transaction(function (Db $db) use ($year): string {
            $db->run('INSERT OR IGNORE INTO receipt_counters (year, last_number) VALUES (:y, 0)', ['y' => $year]);
            $db->run('UPDATE receipt_counters SET last_number = last_number + 1 WHERE year = :y', ['y' => $year]);
            $n = $db->int('SELECT last_number FROM receipt_counters WHERE year = :y', ['y' => $year]);

            return sprintf('%s-%d-%04d', $this->prefix, $year, $n);
        });
    }
}
