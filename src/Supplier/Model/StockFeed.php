<?php

declare(strict_types=1);

namespace App\Supplier\Model;

/** Stock completo (M0: /stock/ non è paginato) con l'impronta del contenuto per saltare i giri senza novità. */
final class StockFeed
{
    /** @param list<StockRow> $rows */
    public function __construct(
        public readonly array $rows,
        public readonly string $hash,
        public readonly bool $complete,
    ) {
    }
}
