<?php

declare(strict_types=1);

namespace Tests\Leak;

use PHPUnit\Framework\TestCase;
use Tests\Support\LeakAssertions;
use Tests\Support\TestApp;

/** Catalogo, vetrina, frammenti, schede rapide ed export: mai costi, mai il fornitore. */
final class CatalogLeakTest extends TestCase
{
    use LeakAssertions;

    public function testCatalogShowcaseQuickViewsAndExport(): void
    {
        $app = new TestApp();
        $app->syncCatalog();
        $app->createCustomer();
        $skus = array_map('strval', $app->db->column('SELECT sku FROM products'));

        $visitor = $app->http('198.51.100.90');
        $pages = ['/vetrina', '/vetrina?page=2&partial=1', '/vetrina?sistema=uk&densita=compatta', '/', '/come-ordinare', '/spedizioni', '/contatti', '/richiedi-accesso'];
        foreach ($skus as $sku) {
            $pages[] = '/vetrina/prodotto/' . rawurlencode($sku);
            $pages[] = '/vetrina/prodotto/' . rawurlencode($sku) . '?partial=1';
        }
        foreach ($pages as $path) {
            $this->assertResponseHasNoLeak($app, $visitor->get($path), $path);
        }

        $customer = $app->http('198.51.100.91');
        $customer->loginCustomer();
        $pages = ['/catalogo', '/catalogo?page=2&partial=1', '/catalogo?ordina=prezzo_asc', '/catalogo?regime=ordinary', '/catalogo?q=nike&sistema=us'];
        foreach ($skus as $sku) {
            $pages[] = '/catalogo/prodotto/' . rawurlencode($sku);
            $pages[] = '/catalogo/prodotto/' . rawurlencode($sku) . '?partial=1';
        }
        foreach ($pages as $path) {
            $response = $customer->get($path);
            self::assertSame(200, $response->getStatusCode(), $path);
            $this->assertResponseHasNoLeak($app, $response, $path);
        }

        $export = $customer->get('/catalogo/export.xlsx');
        $file = (string) tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($file, (string) $export->getBody());
        $zip = new \ZipArchive();
        $zip->open($file);
        $content = $export->getHeaderLine('Content-Disposition');
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $content .= "\n" . $zip->getNameIndex($i) . "\n" . (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($file);
        $this->assertNoLeak($app, $content, 'export Excel');

        foreach ($app->db->column('SELECT id FROM products') as $id) {
            $this->assertResponseHasNoLeak($app, $customer->get('/img/' . $id . '/0-800.jpg'), 'immagine ' . $id);
        }
    }

    public function testAccountRequestEmailsToTheCustomer(): void
    {
        $app = new TestApp();
        $app->syncCatalog();
        foreach (['it', 'en'] as $i => $locale) {
            $app->http('198.51.100.' . (100 + $i))->post('/richiedi-accesso', [
                'company' => 'Leak ' . $locale, 'vat_number' => 'DE12345678' . $i, 'name' => 'N', 'email' => "leak$i@example.com",
                'phone' => '+49 30 1234567', 'country_code' => 'DE', 'address_street' => 'S 1', 'address_city' => 'Berlin',
                'address_zip' => '10119', 'locale' => $locale,
            ]);
            $emails = $app->mail->sentTo("leak$i@example.com");
            self::assertCount(1, $emails);
            $this->assertNoLeak($app, $emails[0]->subject . $emails[0]->html . $emails[0]->text, 'conferma richiesta ' . $locale);
        }
    }
}
