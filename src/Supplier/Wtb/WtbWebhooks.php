<?php

declare(strict_types=1);

namespace App\Supplier\Wtb;

use App\Supplier\Exception\SupplierFormatException;
use App\Supplier\Model\SupplierWebhookEvent;
use App\Supplier\SupplierWebhooks;
use App\Support\Clock;

/**
 * Webhook di WTB (docs/06 § Webhook): busta {id, event, created_at, data}, intestazioni X-WTB-Event,
 * X-WTB-Delivery (id unico) e X-WTB-Signature (t=<unix>,v1=<hex>), firma verificata da WebhookVerifier.
 */
final class WtbWebhooks implements SupplierWebhooks
{
    public const EVENTS = [
        'ping', 'order.created', 'order.confirmed', 'order.paid', 'order.shipped',
        'order.tracking_updated', 'order.updated', 'order.cancelled',
    ];

    /** Stato che l'ordine ha in ogni evento simulato. */
    private const SIMULATED_STATUS = [
        'order.created' => 'received',
        'order.confirmed' => 'confirmed',
        'order.paid' => 'paid',
        'order.shipped' => 'shipped',
        'order.tracking_updated' => 'shipped',
        'order.cancelled' => 'cancelled',
    ];

    public function __construct(
        private readonly WebhookVerifier $verifier,
        private readonly WtbMapper $mapper,
        private readonly WtbConfig $config,
        private readonly Clock $clock,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->verifier->isConfigured();
    }

    public function verify(array $headers, string $rawBody): bool
    {
        return $this->verifier->verify($headers['x-wtb-signature'] ?? '', $rawBody);
    }

    public function parse(array $headers, string $rawBody): SupplierWebhookEvent
    {
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            throw new SupplierFormatException('Corpo del webhook non è JSON valido.');
        }
        $event = is_string($data['event'] ?? null) ? $data['event'] : ($headers['x-wtb-event'] ?? '');
        if (preg_match('/^[a-z_.]{1,60}$/', $event) !== 1) {
            throw new SupplierFormatException('Evento del webhook mancante o non valido.');
        }
        $delivery = $headers['x-wtb-delivery'] ?? '';
        if ($delivery === '' && (is_int($data['id'] ?? null) || is_string($data['id'] ?? null))) {
            $delivery = 'evt-' . $data['id'];
        }
        if (preg_match('/^[A-Za-z0-9._:\-]{1,100}$/', $delivery) !== 1) {
            throw new SupplierFormatException('Intestazione X-WTB-Delivery mancante o non valida.');
        }
        $order = null;
        if (str_starts_with($event, 'order.')) {
            if (!is_array($data['data'] ?? null)) {
                throw new SupplierFormatException('Evento ' . $event . ' senza dati dell\'ordine.');
            }
            $order = $this->mapper->order($data['data']);
        }

        return new SupplierWebhookEvent(
            $delivery,
            $event,
            is_string($data['created_at'] ?? null) ? mb_substr($data['created_at'], 0, 40) : '',
            $order,
        );
    }

    public function events(): array
    {
        return self::EVENTS;
    }

    public function simulate(string $event, ?array $order): array
    {
        if (!in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException('Evento sconosciuto: ' . $event);
        }
        if ($this->config->mode === 'live') {
            throw new \LogicException('Gli eventi simulati non esistono in modalità live.');
        }
        $data = [];
        if ($event !== 'ping') {
            $data = $order ?? $this->blankOrder('SIM-0-0');
            if (isset(self::SIMULATED_STATUS[$event])) {
                $data['status'] = self::SIMULATED_STATUS[$event];
            }
            if ($event === 'order.paid' || ($data['status'] ?? '') === 'shipped') {
                $data['is_paid'] = true;
            }
            if ($event === 'order.shipped' || $event === 'order.tracking_updated') {
                $tracking = is_array($data['tracking'] ?? null) ? array_values($data['tracking']) : [];
                if ($tracking === [] || $event === 'order.tracking_updated') {
                    $number = 'DEMO' . str_pad((string) random_int(0, 999999999), 12, '0', STR_PAD_LEFT);
                    $tracking[] = ['carrier' => 'BRT', 'number' => $number, 'url' => 'https://www.brt.it/it/tracking?parcelID=' . $number];
                }
                $data['tracking'] = $tracking;
            }
        }
        $timestamp = $this->clock->timestamp();
        $body = (string) json_encode([
            'id' => random_int(100000, 999999),
            'event' => $event,
            'created_at' => $this->clock->now()->format(DATE_ATOM),
            'data' => (object) $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'headers' => [
                'content-type' => 'application/json',
                'x-wtb-event' => $event,
                'x-wtb-delivery' => 'sim-' . bin2hex(random_bytes(8)),
                'x-wtb-signature' => WebhookVerifier::sign($this->config->webhookSecret, $timestamp, $body),
            ],
            'body' => $body,
        ];
    }

    public function blankOrder(string $reference): array
    {
        return [
            'id' => null, 'number' => '', 'reference' => $reference, 'status' => 'received',
            'created_at' => $this->clock->now()->format(DATE_ATOM), 'dropship' => true,
            'payment_method' => 'bank_transfer', 'is_paid' => false, 'currency' => 'EUR',
            'subtotal' => '0', 'shipping' => '0', 'vat' => '0', 'total' => '0',
            'lines' => [], 'tracking' => [], 'hand_delivery' => false, 'payment_instructions' => null,
        ];
    }
}
