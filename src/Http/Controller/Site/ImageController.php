<?php

declare(strict_types=1);

namespace App\Http\Controller\Site;

use App\Catalog\ImageService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** /img/<prodotto>/<n>-<larghezza>.jpg: immagini dal nostro dominio (D-011), noindex, cache lunga. */
final class ImageController
{
    public function __construct(private readonly ImageService $images)
    {
    }

    /** @param array<string, string> $args */
    public function show(Request $request, Response $response, array $args): Response
    {
        $file = $this->images->resolve((int) $args['product'], (int) $args['position'], (int) $args['width']);
        if ($file === null) {
            return $this->placeholder($response);
        }
        $response->getBody()->write((string) file_get_contents($file));

        return $response
            ->withHeader('Content-Type', 'image/jpeg')
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->withHeader('Content-Security-Policy', "default-src 'none'");
    }

    private function placeholder(Response $response): Response
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 300"><rect width="400" height="300" fill="#eeeae3"/>'
            . '<path d="M110 190l40-60h60l30 30h50l20 30z" fill="#d5cfc4"/></svg>';
        $response->getBody()->write($svg);

        return $response
            ->withHeader('Content-Type', 'image/svg+xml')
            ->withHeader('Cache-Control', 'public, max-age=300')
            ->withHeader('Content-Security-Policy', "default-src 'none'");
    }
}
