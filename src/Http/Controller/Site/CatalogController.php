<?php

declare(strict_types=1);

namespace App\Http\Controller\Site;

use App\Catalog\CatalogExport;
use App\Catalog\CatalogFilters;
use App\Catalog\CatalogPage;
use App\Http\Req;
use App\Http\Responder;
use App\Settings\SettingsService;
use App\Support\Xlsx;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/** /catalogo (dietro login): card, scheda rapida, filtri, ricerca, "carica altri", export Excel. */
final class CatalogController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CatalogPage $page,
        private readonly CatalogExport $export,
        private readonly SettingsService $settings,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $prefs = $this->page->preferences(Req::session($request), $request->getQueryParams());
        $partial = Req::query($request, 'partial') === '1';
        $data = $this->page->index($request->getQueryParams(), true, '/catalogo', $prefs, $partial);

        return $this->responder->render($response, $partial ? 'catalog/_cards.twig' : 'catalog/index.twig', $data);
    }

    /** @param array<string, string> $args */
    public function product(Request $request, Response $response, array $args): Response
    {
        $prefs = $this->page->preferences(Req::session($request), $request->getQueryParams());
        $data = $this->page->product($args['sku'], true, '/catalogo', $prefs) ?? throw new HttpNotFoundException($request);
        $data['cart_items'] = (new \App\Orders\Cart(Req::session($request)))->items();

        return $this->responder->render($response, Req::query($request, 'partial') === '1' ? 'catalog/_product.twig' : 'catalog/product.twig', $data);
    }

    public function export(Request $request, Response $response): Response
    {
        $prefs = $this->page->preferences(Req::session($request), []);
        $filters = CatalogFilters::fromQuery($request->getQueryParams(), true, $prefs['size_system']);
        $content = $this->export->build($filters, $this->settings->string('company.site_name'));

        return $this->responder->download($response, $content, 'catalogo-' . gmdate('Y-m-d') . '.xlsx', Xlsx::MIME);
    }
}
