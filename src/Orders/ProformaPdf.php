<?php

declare(strict_types=1);

namespace App\Orders;

use App\Settings\SettingsService;
use App\Support\Config;
use App\Support\Translator;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Pro-forma PDF (dompdf) nella lingua del cliente: righe divise in blocchi margine / ordinario con le loro
 * diciture (docs/04). Solo dati da OrderPresenter: mai costi, mai il fornitore.
 */
final class ProformaPdf
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Translator $translator,
        private readonly SettingsService $settings,
        private readonly Config $config,
    ) {
    }

    /** @param array<string, mixed> $order @param list<array<string, mixed>> $lines */
    public function html(array $order, array $lines): string
    {
        $previous = $this->translator->locale();
        $this->translator->setLocale((string) $order['locale']);
        try {
            return $this->twig->render('pdf/proforma.twig', [
                'order' => OrderPresenter::forCustomer($order, $lines),
                'bank' => OrderMailer::bank($this->config),
                'email_locale' => (string) $order['locale'],
            ]);
        } finally {
            $this->translator->setLocale($previous);
        }
    }

    /** @param array<string, mixed> $order @param list<array<string, mixed>> $lines */
    public function render(array $order, array $lines): string
    {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setTempDir(sys_get_temp_dir());
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($order, $lines), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->addInfo('Title', $this->translator->get('pdf.title', [], (string) $order['locale']) . ' ' . (string) ($order['receipt_number'] ?? $order['id']));
        $dompdf->addInfo('Author', $this->settings->string('company.name'));
        $dompdf->addInfo('Creator', $this->settings->string('company.site_name'));
        $dompdf->addInfo('Producer', $this->settings->string('company.site_name'));
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** Nome del file (mai il nome del fornitore). @param array<string, mixed> $order */
    public static function filename(array $order): string
    {
        return 'proforma-' . preg_replace('/[^A-Za-z0-9-]/', '', (string) ($order['receipt_number'] ?? $order['id'])) . '.pdf';
    }
}
