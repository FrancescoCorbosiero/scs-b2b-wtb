<?php

declare(strict_types=1);

namespace Tests\Integration\Site;

use PHPUnit\Framework\TestCase;
use Tests\Support\HttpClient;
use Tests\Support\TestApp;

final class CatalogPagesTest extends TestCase
{
    private TestApp $app;
    private HttpClient $http;

    protected function setUp(): void
    {
        $this->app = new TestApp();
        $this->app->syncCatalog();
        $this->app->createCustomer();
        $this->http = $this->app->http();
        $this->http->loginCustomer();
    }

    /** @return list<string> SKU nell'ordine della griglia */
    private function skus(string $query = ''): array
    {
        $response = $this->http->get('/catalogo' . ($query === '' ? '' : '?' . $query));
        self::assertSame(200, $response->getStatusCode(), $query);
        preg_match_all('#<code>(ZX[0-9]{4}-[0-9]{3})</code>#', (string) $response->getBody(), $m);

        return $m[1];
    }

    public function testCatalogRequiresLogin(): void
    {
        $visitor = $this->app->http('198.51.100.99');
        $response = $visitor->get('/catalogo?brand[]=Nike');
        self::assertSame(303, $response->getStatusCode());
        parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $q);
        self::assertSame('/catalogo?brand%5B%5D=Nike', $q['next'], 'Dopo il login si torna sugli stessi filtri');
        self::assertSame(303, $visitor->get('/catalogo/export.xlsx')->getStatusCode());
    }

    public function testSoldOutAtTheBottomAndAvailableOnly(): void
    {
        $all = $this->skus();
        self::assertCount(11, $all);
        self::assertSame('ZX1010-010', end($all), 'Esaurito in fondo');
        self::assertNotContains('ZX1010-010', $this->skus('disponibili=1'));
    }

    public function testComposableFilters(): void
    {
        self::assertSame(['ZX1001-100', 'ZX1003-300', 'ZX1009-900'], $this->sorted($this->skus('brand[]=Nike')));
        self::assertSame(['ZX1003-300'], $this->skus('brand[]=Nike&eta[]=ps'));
        self::assertSame(['ZX1001-100', 'ZX1002-200'], $this->sorted($this->skus('eta[]=gs')));
        self::assertSame(['ZX1005-500', 'ZX1009-900'], $this->sorted($this->skus('regime=ordinary')));
        self::assertSame(['ZX1005-500'], $this->skus('taglie[]=' . rawurlencode('36 2/3')));
        self::assertSame(['ZX1008-800'], $this->skus('sistema=us&taglie[]=7W'));
        self::assertSame(['ZX1007-700'], $this->skus('cat=' . rawurlencode('Sneakers/New Balance/9060')));
        self::assertNotContains('ZX1004-400', $this->skus('pronta=1&brand[]=ASICS&taglie[]=40'), 'La 40 di ZX1004 è solo stock esteso, ma ZX1004 ha la 42 in pronta');
        self::assertSame(['ZX1006-600'], $this->skus('q=premium'));
        self::assertSame(['ZX1005-500'], $this->skus('q=2000000900541'), 'Ricerca per EAN');
        self::assertSame(['ZX1007-700'], $this->skus('disp=alta'));
    }

    public function testPriceFilterAndSorting(): void
    {
        $byPrice = $this->skus('ordina=prezzo_asc&disponibili=1');
        self::assertSame('ZX1003-300', $byPrice[0], 'Il più economico');
        self::assertSame('ZX1008-800', end($byPrice), 'Il più caro');
        self::assertSame(['ZX1008-800'], $this->skus('prezzo_min=200'));
        self::assertSame(['ZX1003-300'], $this->skus('prezzo_max=60'));
        $byName = $this->skus('ordina=nome&disponibili=1');
        self::assertSame('ZX1005-500', $byName[0], '"adidas Samba" viene prima di "ASICS" senza distinguere le maiuscole');
    }

    public function testLoadMoreAndPartials(): void
    {
        $this->app->get(\App\Settings\SettingsService::class)->setValue('catalog.per_page', 6, 'test');
        $first = (string) $this->http->get('/catalogo')->getBody();
        self::assertStringContainsString('data-load-more', $first);
        preg_match_all('#<code>(ZX[0-9]{4}-[0-9]{3})</code>#', $first, $m1);
        self::assertCount(6, $m1[1]);
        $partial = (string) $this->http->get('/catalogo?page=2&partial=1')->getBody();
        self::assertStringNotContainsString('<html', $partial, 'Frammento senza layout');
        preg_match_all('#<code>(ZX[0-9]{4}-[0-9]{3})</code>#', $partial, $m2);
        self::assertCount(5, $m2[1]);
        self::assertSame([], array_intersect($m1[1], $m2[1]));
        preg_match_all('#<code>(ZX[0-9]{4}-[0-9]{3})</code>#', (string) $this->http->get('/catalogo?page=2')->getBody(), $m3);
        self::assertCount(11, $m3[1], 'Senza JavaScript la pagina 2 mostra tutto fino a lì');
    }

    public function testQuickViewShowsTiersAndPrices(): void
    {
        $html = (string) $this->http->get('/catalogo/prodotto/ZX1001-100?partial=1')->getBody();
        self::assertStringNotContainsString('<html', $html);
        self::assertStringContainsString('Pronta consegna', $html);
        self::assertStringContainsString('Stock esteso', $html);
        self::assertStringContainsString('75,00', $html, 'Prezzo di vendita della pronta consegna');
        self::assertStringContainsString('77,00', $html, '73,19 + 5% = 76,85 → 77,00');
        self::assertSame(200, $this->http->get('/catalogo/prodotto/ZX1001-100')->getStatusCode());
        self::assertSame(404, $this->http->get('/catalogo/prodotto/NON-ESISTE')->getStatusCode());
    }

    public function testSizeSystemPreferenceIsRemembered(): void
    {
        $this->http->get('/catalogo?sistema=us');
        $html = (string) $this->http->get('/catalogo/prodotto/ZX1001-100?partial=1')->getBody();
        self::assertStringContainsString('4Y', $html);
        self::assertStringContainsString('EU 36', $html);
    }

    public function testExportContainsSalePricesOnly(): void
    {
        $response = $this->http->get('/catalogo/export.xlsx?brand[]=Nike');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('spreadsheetml', $response->getHeaderLine('Content-Type'));
        self::assertMatchesRegularExpression('/filename="catalogo-\d{4}-\d{2}-\d{2}\.xlsx"/', $response->getHeaderLine('Content-Disposition'));
        $xml = $this->xlsxText((string) $response->getBody());
        self::assertStringContainsString('ZX1001-100', $xml);
        self::assertStringNotContainsString('ZX1004-400', $xml, 'Rispetta i filtri');
        self::assertStringContainsString('>75<', $xml, 'Prezzo di vendita come numero');
        self::assertStringNotContainsString('71.37', $xml);
    }

    /** @param list<string> $list @return list<string> */
    private function sorted(array $list): array
    {
        sort($list);

        return $list;
    }

    private function xlsxText(string $bytes): string
    {
        $file = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents((string) $file, $bytes);
        $zip = new \ZipArchive();
        $zip->open((string) $file);
        $text = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $text .= (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink((string) $file);

        return $text;
    }
}
