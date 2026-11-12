<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use Slim\Views\Twig;

/** Risposte HTML, JSON e redirect. */
final class Responder
{
    public function __construct(private readonly Twig $twig)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(ResponseInterface $response, string $template, array $data = [], int $status = 200): ResponseInterface
    {
        return $this->twig->render($response->withStatus($status), $template, $data);
    }

    /** @param array<string, mixed> $data */
    public function fetch(string $template, array $data = []): string
    {
        return $this->twig->fetch($template, $data);
    }

    public function redirect(ResponseInterface $response, string $url, int $status = 303): ResponseInterface
    {
        return $response->withHeader('Location', $url)->withStatus($status);
    }

    /** @param array<mixed> $data */
    public function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
    }

    public function download(ResponseInterface $response, string $content, string $filename, string $mime): ResponseInterface
    {
        $response->getBody()->write($content);
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?? 'file';

        return $response
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $safe . '"')
            ->withHeader('Content-Length', (string) strlen($content));
    }
}
