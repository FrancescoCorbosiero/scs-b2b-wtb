<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ViewContext;
use App\Support\Session;
use App\Support\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lingua: ?lang=it|en (ricordata in sessione) → lingua del cliente → italiano. L'area admin è solo in italiano.
 */
final class LocaleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Translator $translator,
        private readonly ViewContext $context,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $locale = Translator::DEFAULT_LOCALE;
        if ($this->context->area !== 'admin') {
            $session = $request->getAttribute('session');
            $param = $request->getQueryParams()['lang'] ?? null;
            if (is_string($param) && in_array($param, Translator::LOCALES, true)) {
                $locale = $param;
                if ($session instanceof Session) {
                    $session->set('locale', $locale);
                }
            } elseif ($session instanceof Session && is_string($session->get('locale'))) {
                $locale = Translator::normalize($session->get('locale'));
            } elseif ($this->context->customer !== null) {
                $locale = Translator::normalize((string) $this->context->customer['locale']);
            }
        }
        $this->translator->setLocale($locale);

        return $handler->handle($request->withAttribute('locale', $locale));
    }
}
