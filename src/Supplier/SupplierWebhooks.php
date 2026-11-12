<?php

declare(strict_types=1);

namespace App\Supplier;

use App\Supplier\Exception\SupplierFormatException;
use App\Supplier\Model\SupplierWebhookEvent;

/**
 * Webhook del fornitore: firma, lettura dell'evento, eventi simulati per le prove.
 * Le intestazioni arrivano con i nomi in minuscolo.
 */
interface SupplierWebhooks
{
    /** Il segreto è impostato? Senza segreto nessun evento è valido. */
    public function isConfigured(): bool;

    /** @param array<string, string> $headers */
    public function verify(array $headers, string $rawBody): bool;

    /**
     * @param array<string, string> $headers
     * @throws SupplierFormatException corpo non valido o ordine illeggibile
     */
    public function parse(array $headers, string $rawBody): SupplierWebhookEvent;

    /** @return list<string> eventi che il fornitore può inviare */
    public function events(): array;

    /**
     * Evento finto firmato col segreto configurato ("Simula evento", solo fixture e test).
     *
     * @param array<string, mixed>|null $order ordine nella forma del fornitore (null per ping)
     * @return array{headers: array<string, string>, body: string}
     */
    public function simulate(string $event, ?array $order): array;

    /**
     * Ordine minimo nella forma del fornitore, per simulare eventi su un ordine senza risposta salvata.
     *
     * @return array<string, mixed>
     */
    public function blankOrder(string $reference): array;
}
