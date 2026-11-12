<?php

declare(strict_types=1);

namespace App\Supplier\Exception;

/**
 * 400: dati non validi, ordine minimo, paia non disponibili. Il fornitore elenca in "stock" cosa manca
 * e negli altri campi il motivo (M0: oggetto campo → messaggio, stringa o lista).
 */
final class SupplierRejectedException extends SupplierException
{
    /**
     * @param array<string, list<string>> $fieldErrors
     */
    public function __construct(
        string $message,
        public readonly array $fieldErrors = [],
    ) {
        parent::__construct($message, 400);
    }

    /** @return list<string> righe del campo "stock": paia mancanti */
    public function stockIssues(): array
    {
        return $this->fieldErrors['stock'] ?? [];
    }

    public function hasStockIssues(): bool
    {
        return $this->stockIssues() !== [];
    }
}
