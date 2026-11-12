<?php

declare(strict_types=1);

namespace App\Orders;

use App\Settings\SettingsService;
use App\Support\Config;
use App\Support\Mail\Mailer;

/**
 * Email degli ordini: al cliente nella sua lingua (solo OrderPresenter, niente costi), all'admin in italiano
 * con costi, margini, preventivo del fornitore e avvisi (docs/06 § Checkout punto 5).
 */
final class OrderMailer
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly SettingsService $settings,
        private readonly Config $config,
        private readonly ProformaPdf $pdf,
    ) {
    }

    /** @return array{holder: string, bank: string, iban: string, bic: string} */
    public static function bank(Config $config): array
    {
        return [
            'holder' => $config->string('BANK_HOLDER'),
            'bank' => $config->string('BANK_NAME'),
            'iban' => $config->string('BANK_IBAN'),
            'bic' => $config->string('BANK_BIC'),
        ];
    }

    /** @param array<string, mixed> $order @param list<array<string, mixed>> $lines */
    public function orderReceived(array $order, array $lines): bool
    {
        return $this->mailer->sendTemplate('order_received', [(string) $order['email']], [
            'order' => OrderPresenter::forCustomer($order, $lines),
            'bank' => self::bank($this->config),
            'link' => $this->config->appUrl() . '/account/ordini/' . $order['id'],
        ], (string) $order['locale'], [], $this->settings->string('company.email'));
    }

    /** @param array<string, mixed> $order @param list<array<string, mixed>> $lines */
    public function orderReceivedAdmin(array $order, array $lines): bool
    {
        return $this->mailer->sendTemplate('order_admin', $this->settings->list('company.admin_emails'), [
            'order' => $order,
            'lines' => $lines,
            'quote' => $order['quote_snapshot'] === null ? null : json_decode((string) $order['quote_snapshot'], true),
            'link' => $this->config->appUrl() . '/admin/ordini/' . $order['id'],
        ], 'it', [], (string) $order['email']);
    }

    /** @param array<string, mixed> $order @param list<array<string, mixed>> $lines */
    public function orderConfirmed(array $order, array $lines): bool
    {
        return $this->mailer->sendTemplate('order_confirmed', [(string) $order['email']], [
            'order' => OrderPresenter::forCustomer($order, $lines),
            'link' => $this->config->appUrl() . '/account/ordini/' . $order['id'],
        ], (string) $order['locale'], [[
            'name' => ProformaPdf::filename($order),
            'content' => $this->pdf->render($order, $lines),
            'mime' => 'application/pdf',
        ]], $this->settings->string('company.email'));
    }

    /**
     * Spedizione al cliente, nella sua lingua: corriere e numero; il link solo se è di un corriere ammesso
     * (TrackingPresenter, docs/06 § Tracking). $update = true: solo i numeri aggiunti dopo la prima email.
     *
     * @param array<string, mixed> $order
     * @param list<array{carrier: string, number: string, url: string}> $tracking già filtrato da TrackingPresenter
     */
    public function orderShipped(array $order, array $tracking, bool $update): bool
    {
        return $this->mailer->sendTemplate('order_shipped', [(string) $order['email']], [
            'order' => OrderPresenter::forCustomer($order, []),
            'tracking' => $tracking,
            'update' => $update,
            'link' => $this->config->appUrl() . '/account/ordini/' . $order['id'],
        ], (string) $order['locale'], [], $this->settings->string('company.email'));
    }

    /** @param array<string, mixed> $order @param list<array<string, mixed>> $lines */
    public function orderUpdated(array $order, array $lines): bool
    {
        return $this->mailer->sendTemplate('order_updated', [(string) $order['email']], [
            'order' => OrderPresenter::forCustomer($order, $lines),
            'bank' => self::bank($this->config),
            'link' => $this->config->appUrl() . '/account/ordini/' . $order['id'],
        ], (string) $order['locale'], [], $this->settings->string('company.email'));
    }
}
