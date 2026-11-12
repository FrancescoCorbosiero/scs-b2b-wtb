<?php

declare(strict_types=1);

namespace Tests\Integration\Site;

use App\Shipping\ZoneRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestApp;

final class ShowcaseAndPublicPagesTest extends TestCase
{
    public function testShowcaseHasNoPricesInTheData(): void
    {
        $app = new TestApp();
        $app->syncCatalog();
        $http = $app->http();
        $outputs = [
            '/vetrina' => (string) $http->get('/vetrina')->getBody(),
            'partial' => (string) $http->get('/vetrina?page=2&partial=1')->getBody(),
            'quick' => (string) $http->get('/vetrina/prodotto/ZX1001-100?partial=1')->getBody(),
            'page' => (string) $http->get('/vetrina/prodotto/ZX1007-700')->getBody(),
            'price-params' => (string) $http->get('/vetrina?ordina=prezzo_desc&prezzo_min=100')->getBody(),
        ];
        foreach ($outputs as $where => $html) {
            self::assertDoesNotMatchRegularExpression('/\d+,\d{2}\s*€/u', $html, 'Prezzo in ' . $where);
            self::assertStringNotContainsString('€', $html, 'Simbolo dell\'euro in ' . $where);
        }
        self::assertStringContainsString('Riservato ai rivenditori', $outputs['/vetrina']);
        self::assertStringContainsString('Pronta consegna', $outputs['quick'], 'Disponibilità e tempi sì');
        self::assertStringNotContainsString('export.xlsx', $outputs['/vetrina'], 'Niente export in vetrina');
        self::assertStringContainsString('ZX1008-800', $outputs['price-params'], 'Il filtro prezzo è ignorato');
        self::assertSame('noindex, nofollow', $http->get('/vetrina')->getHeaderLine('X-Robots-Tag'));
    }

    public function testLoggedInCustomersAreRedirectedToTheCatalog(): void
    {
        $app = new TestApp();
        $app->syncCatalog();
        $app->createCustomer();
        $http = $app->http();
        $http->loginCustomer();
        $response = $http->get('/vetrina?brand%5B%5D=Nike');
        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/catalogo?brand%5B%5D=Nike', $response->getHeaderLine('Location'));
    }

    public function testPublicPagesAreIndexableAndUseRealValues(): void
    {
        $app = new TestApp();
        $app->syncCatalog();
        $app->get(ZoneRepository::class)->update('IT', ['fee_cents' => 2000, 'free_from_items' => 20]);
        $http = $app->http();
        foreach (['/', '/come-ordinare', '/spedizioni', '/contatti', '/richiedi-accesso'] as $path) {
            $response = $http->get($path);
            self::assertSame(200, $response->getStatusCode(), $path);
            self::assertFalse($response->hasHeader('X-Robots-Tag'), $path . ' è indicizzabile');
            $html = (string) $response->getBody();
            self::assertStringNotContainsString('noindex', $html, $path);
            self::assertStringNotContainsString('/img/', $html, $path . ': mai foto prodotto');
            self::assertStringNotContainsString('ZX10', $html, $path . ': mai prodotti');
        }
        $shipping = (string) $http->get('/spedizioni')->getBody();
        self::assertStringContainsString('20,00', $shipping, 'Tariffa dal database');
        self::assertStringContainsString('gratis da 20 paia', $shipping);
        self::assertStringContainsString('Svizzera', $shipping, 'Dazi a carico del destinatario per la zona extra UE');
        self::assertStringContainsString('IT60X0542811101000000123456', (string) $http->get('/contatti')->getBody(), 'Coordinate BANK_*');
        $en = (string) $http->get('/spedizioni?lang=en')->getBody();
        self::assertStringContainsString('Estimated delivery', $en);
        self::assertStringContainsString('Switzerland', $en);
    }
}
