<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\ErrorHandlerInterface;
use Slim\Views\Twig;
use Throwable;

/**
 * Pagina d'errore cortese, dettagli solo nel log (docs/09). Mai messaggi tecnici al cliente.
 */
final class ErrorHandler implements ErrorHandlerInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly Twig $twig,
        private readonly LoggerInterface $logger,
        private readonly ViewContext $context,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;
        if ($status < 400 || $status > 599) {
            $status = 500;
        }
        $isCsrf = $exception instanceof HttpForbiddenException && $exception->getMessage() === 'csrf';
        if ($status >= 500) {
            $this->logger->error('Errore non gestito', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile() . ':' . $exception->getLine(),
                'path' => $request->getUri()->getPath(),
                'trace' => mb_substr($exception->getTraceAsString(), 0, 4000),
            ]);
        } elseif ($isCsrf) {
            $this->logger->notice('Token CSRF non valido', ['path' => $request->getUri()->getPath()]);
        }

        $kind = match (true) {
            $isCsrf => 'csrf',
            $exception instanceof HttpNotFoundException => 'not_found',
            $exception instanceof HttpMethodNotAllowedException => 'not_found',
            $status === 403 => 'forbidden',
            $status === 429 => 'too_many',
            default => 'server',
        };
        if ($exception instanceof HttpMethodNotAllowedException) {
            $status = 404;
        }
        $response = $this->responses->createResponse($status);
        $wantsJson = str_contains($request->getHeaderLine('Accept'), 'application/json')
            || str_starts_with($request->getUri()->getPath(), '/webhooks/');
        if ($wantsJson) {
            $response->getBody()->write((string) json_encode(['error' => $kind]));

            return $response->withHeader('Content-Type', 'application/json');
        }
        try {
            return $this->twig->render($response, 'errors/error.twig', [
                'kind' => $kind,
                'status' => $status,
                'details' => $displayErrorDetails && $status >= 500 ? $exception->getMessage() . "\n" . $exception->getFile() . ':' . $exception->getLine() : null,
                'area' => $this->context->area,
            ]);
        } catch (Throwable $e) {
            $this->logger->critical('Pagina d\'errore non disponibile', ['message' => $e->getMessage()]);
            $response->getBody()->write('Errore. Riprova più tardi.');

            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }
    }
}
