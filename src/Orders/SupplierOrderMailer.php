<?php

declare(strict_types=1);

namespace App\Orders;

use App\Settings\SettingsService;
use App\Support\Config;
use App\Support\Mail\Mailer;
use App\Support\Money;

/**
 * Email all'admin (in italiano) sull'ordine al fornitore: creato con le istruzioni di pagamento, problemi,
 * annullamento, differenze. Solo agli indirizzi company.admin_emails: possono contenere costi.
 */
final class SupplierOrderMailer
{
    public const KINDS = ['created', 'simulated', 'problem', 'cancelled', 'differences', 'shipped_cancelled'];

    public function __construct(
        private readonly Mailer $mailer,
        private readonly SettingsService $settings,
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed>|null $supplierOrder riga di supplier_orders
     * @param list<string> $details righe di testo (messaggi del fornitore, differenze)
     */
    public function notify(string $kind, array $order, ?array $supplierOrder, array $details = []): bool
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Tipo di email sconosciuto: ' . $kind);
        }

        return $this->mailer->sendTemplate('supplier_order_admin', $this->settings->list('company.admin_emails'), [
            'kind' => $kind,
            'params' => [
                'id' => (int) $order['id'],
                'reference' => (string) ($supplierOrder['reference'] ?? ''),
                'mode' => strtoupper((string) ($supplierOrder['mode'] ?? $order['mode'])),
                'total' => ($supplierOrder['total_cents'] ?? null) === null ? '—' : Money::format((int) $supplierOrder['total_cents']),
            ],
            'order' => $order,
            'supplier' => $supplierOrder,
            'instructions' => $supplierOrder === null ? [] : PaymentInstructions::fromJson($supplierOrder['payment_instructions'] ?? null),
            'details' => $details,
            'link' => $this->config->appUrl() . '/admin/ordini/' . $order['id'] . '#fornitore',
        ], 'it');
    }
}
