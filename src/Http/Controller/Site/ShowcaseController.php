<?php

declare(strict_types=1);

namespace App\Http\Controller\Site;

use App\Catalog\CatalogPage;
use App\Http\Req;
use App\Http\Responder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * /vetrina: lo stesso catalogo aperto a tutti ma SENZA prezzi nei dati (noindex). Chi ha la sessione
 * viene portato su /catalogo con gli stessi filtri.
 */
final class ShowcaseController
{
    public function __construct(
        private readonly Responder $responder,
        private readonly CatalogPage $page,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        if (Req::customer($request) !== null) {
            $query = $request->getUri()->getQuery();

            return $this->responder->redirect($response, '/catalogo' . ($query !== '' ? '?' . $query : ''), 302);
        }
        $prefs = $this->page->preferences(Req::session($request), $request->getQueryParams());
        $partial = Req::query($request, 'partial') === '1';
        $data = $this->page->index($request->getQueryParams(), false, '/vetrina', $prefs, $partial);

        return $this->responder->render($response, $partial ? 'catalog/_cards.twig' : 'catalog/index.twig', $data);
    }

    /** @param array<string, string> $args */
    public function product(Request $request, Response $response, array $args): Response
    {
        if (Req::customer($request) !== null) {
            return $this->responder->redirect($response, '/catalogo/prodotto/' . rawurlencode($args['sku']), 302);
        }
        $prefs = $this->page->preferences(Req::session($request), $request->getQueryParams());
        $data = $this->page->product($args['sku'], false, '/vetrina', $prefs) ?? throw new HttpNotFoundException($request);

        return $this->responder->render($response, Req::query($request, 'partial') === '1' ? 'catalog/_product.twig' : 'catalog/product.twig', $data);
    }
}
