<?php

declare(strict_types=1);

namespace App\Orders;

use App\Supplier\Exception\SupplierFormatException;
use App\Supplier\SupplierWebhooks;
use App\Support\Clock;
use App\Support\Db;
use Psr\Log\LoggerInterface;

/**
 * Webhook del fornitore (docs/06 § Webhook): corpo grezzo ≤ 1 MB, solo JSON, firma valida (altrimenti 401 e
 * una riga di log, nessuna scrittura nel database), duplicati scartati per delivery id, evento salvato,
 * stati aggiornati da SupplierOrderUpdater; le email partono dopo la risposta.
 */
final class WebhookProcessor
{
    public const MAX_BYTES = 1048576;
    public const OUTCOMES = ['applied', 'stale', 'unknown_order', 'ping', 'ignored', 'error'];

    public function __construct(
        private readonly SupplierWebhooks $webhooks,
        private readonly SupplierOrderUpdater $updater,
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, string> $headers nomi in minuscolo
     * @return array{status: int, outcome: string}
     */
    public function handle(array $headers, string $rawBody, bool $simulated = false): array
    {
        if (strlen($rawBody) > self::MAX_BYTES) {
            $this->logger->warning('Webhook rifiutato: corpo troppo grande', ['bytes' => strlen($rawBody)]);

            return ['status' => 413, 'outcome' => 'too_large'];
        }
        $type = strtolower(trim(explode(';', $headers['content-type'] ?? '')[0]));
        if ($type !== 'application/json') {
            return ['status' => 415, 'outcome' => 'unsupported_type'];
        }
        if (!$this->webhooks->verify($headers, $rawBody)) {
            // Nessun segreto e nessun corpo nel log: solo cosa serve a capire chi bussa.
            $this->logger->warning('Webhook con firma non valida', [
                'configured' => $this->webhooks->isConfigured(),
                'delivery' => mb_substr($headers['x-wtb-delivery'] ?? '', 0, 100),
                'bytes' => strlen($rawBody),
            ]);

            return ['status' => 401, 'outcome' => 'invalid_signature'];
        }

        try {
            $event = $this->webhooks->parse($headers, $rawBody);
        } catch (SupplierFormatException $e) {
            // Firmato ma illeggibile: si conserva per l'admin e si risponde 200 (ritentare non lo aggiusterebbe).
            $delivery = $headers['x-wtb-delivery'] ?? '';
            $delivery = preg_match('/^[A-Za-z0-9._:\-]{1,100}$/', $delivery) === 1 ? $delivery : 'invalid-' . substr(hash('sha256', $rawBody), 0, 24);
            $this->store($delivery, mb_substr($headers['x-wtb-event'] ?? '?', 0, 60), $rawBody, $simulated, 'error', $e->getMessage());
            $this->logger->error('Webhook firmato ma non leggibile', ['delivery' => $delivery, 'error' => $e->getMessage()]);

            return ['status' => 200, 'outcome' => 'error'];
        }

        $existing = $this->db->one('SELECT id, outcome FROM webhook_events WHERE delivery_id = :d', ['d' => $event->deliveryId]);
        if ($existing !== null && $existing['outcome'] !== 'error' && $existing['outcome'] !== '') {
            return ['status' => 200, 'outcome' => 'duplicate'];
        }
        $eventId = $existing === null
            ? $this->store($event->deliveryId, $event->event, $rawBody, $simulated, '', '')
            : (int) $existing['id'];
        if ($eventId === 0) {
            return ['status' => 200, 'outcome' => 'duplicate'];
        }

        if ($event->isPing()) {
            return $this->finish($eventId, 'ping', null, '');
        }
        if ($event->order === null) {
            return $this->finish($eventId, 'ignored', null, '');
        }
        try {
            $result = $this->updater->apply($event->order, $event->event);
        } catch (\Throwable $e) {
            // Errore nostro (database occupato, bug): 500, così il fornitore riprova e l'evento si rielabora.
            $this->logger->error('Webhook non elaborato', ['delivery' => $event->deliveryId, 'event' => $event->event, 'error' => $e->getMessage()]);
            $this->finish($eventId, 'error', null, mb_substr($e->getMessage(), 0, 500));

            return ['status' => 500, 'outcome' => 'error'];
        }
        if ($result['outcome'] === 'unknown_order') {
            $this->logger->warning('Webhook per un ordine sconosciuto', ['delivery' => $event->deliveryId, 'event' => $event->event, 'reference' => $event->order->reference]);
        }

        return $this->finish($eventId, $result['outcome'], $result['supplier_order_id'], '');
    }

    /** @return int id dell'evento, 0 se un'altra richiesta l'ha appena salvato */
    private function store(string $delivery, string $event, string $rawBody, bool $simulated, string $outcome, string $error): int
    {
        try {
            return $this->db->insert('webhook_events', [
                'delivery_id' => $delivery,
                'event' => $event,
                'signature_valid' => 1,
                'payload' => $rawBody,
                'simulated' => $simulated ? 1 : 0,
                'received_at' => $this->clock->iso(),
                'processed_at' => $outcome === '' ? null : $this->clock->iso(),
                'outcome' => $outcome,
                'error' => mb_substr($error, 0, 500),
            ]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return 0;
            }
            throw $e;
        }
    }

    /** @return array{status: int, outcome: string} */
    private function finish(int $eventId, string $outcome, ?int $supplierOrderId, string $error): array
    {
        $this->db->update('webhook_events', [
            'outcome' => $outcome,
            'supplier_order_id' => $supplierOrderId,
            'processed_at' => $this->clock->iso(),
            'error' => $error,
        ], 'id = :id', ['id' => $eventId]);

        return ['status' => 200, 'outcome' => $outcome];
    }
}
