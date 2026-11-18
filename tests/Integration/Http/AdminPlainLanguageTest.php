<?php

declare(strict_types=1);

namespace Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Tests\Support\OrderHelpers;
use Tests\Support\TestApp;

/**
 * Il titolare non è tecnico (D-021): nelle pagine admin che usa non devono comparire nomi di variabili, codici
 * delle modalità o gergo da sviluppatori. I dettagli tecnici restano solo dentro i riquadri <details> apposta.
 */
final class AdminPlainLanguageTest extends TestCase
{
    use OrderHelpers;

    private const JARGON = [
        'WTB_', '.env', 'fixture', 'FIXTURE', 'webhook', 'Webhook', 'payload', 'Sync', ' sync', 'feed',
        'reference', 'Reference', 'on_confirm', 'on_request', 'bank_transfer', 'kill switch', 'scheduler',
        'API', 'job', 'Job', 'default', '401', 'D-0', 'docs/', '/admin/', '(valore iniziale))',
    ];

    public function testOwnerPagesUsePlainLanguage(): void
    {
        $app = new TestApp();
        [, $admin] = $this->placeOrder($app);
        $admin->post('/admin/ordini/1001/conferma');
        $admin->post('/admin/webhook/simula', ['event' => 'order.shipped', 'reference' => 'SCSW-1001-1']);
        $admin->post('/admin/sync/stock_sync');
        $admin->post('/admin/impostazioni/orders.min_items', ['value' => '6']);

        foreach ([
            '/admin', '/admin/impostazioni', '/admin/sync', '/admin/webhook', '/admin/ordini', '/admin/ordini/1001',
            '/admin/ordini/nuovo?q=ZX1001', '/admin/ordini-fornitore', '/admin/prodotti', '/admin/spedizione',
        ] as $path) {
            $response = $admin->get($path);
            self::assertSame(200, $response->getStatusCode(), $path);
            $text = self::visibleText((string) $response->getBody());
            foreach (self::JARGON as $word) {
                self::assertStringNotContainsString($word, $text, $path . ': "' . $word . '" in "' . self::around($text, $word) . '"');
            }
        }
    }

    /** Testo che il titolare vede: senza tag, script, stili e senza il contenuto dei riquadri dei dettagli tecnici. */
    private static function visibleText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|details)\b.*?</\1>#si', ' ', $html);
        $html = (string) preg_replace('#<[^>]+>#', ' ', $html);

        return (string) preg_replace('/\s+/', ' ', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function around(string $text, string $word): string
    {
        $at = strpos($text, $word);

        return $at === false ? '' : substr($text, max(0, $at - 60), 140);
    }
}
