<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Support\Translator;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * Email da template Twig (emails/<nome>.twig con i blocchi subject, html, text), nella lingua indicata.
 * Un errore di invio non interrompe mai il flusso che lo chiama: si registra e si restituisce false.
 */
final class Mailer
{
    public function __construct(
        private readonly MailTransport $transport,
        private readonly Environment $twig,
        private readonly Translator $translator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $to
     * @param array<string, mixed> $data
     * @param list<array{name: string, content: string, mime: string}> $attachments
     */
    public function sendTemplate(string $template, array $to, array $data, string $locale, array $attachments = [], ?string $replyTo = null): bool
    {
        $to = array_values(array_filter($to, static fn (string $a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
        if ($to === []) {
            $this->logger->warning('Email non inviata: nessun destinatario valido', ['template' => $template]);

            return false;
        }
        $previous = $this->translator->locale();
        $this->translator->setLocale($locale);
        try {
            $tpl = $this->twig->load('emails/' . $template . '.twig');
            $context = $data + ['email_locale' => $locale];
            $email = new Email(
                $to,
                trim(preg_replace('/\s+/', ' ', $tpl->renderBlock('subject', $context)) ?? ''),
                $tpl->renderBlock('html', $context),
                trim($tpl->renderBlock('text', $context)) . "\n",
                $replyTo,
                $attachments,
            );
        } finally {
            $this->translator->setLocale($previous);
        }

        return $this->send($email, $template);
    }

    public function send(Email $email, string $label = 'email'): bool
    {
        try {
            $this->transport->send($email);
            $this->logger->info('Email inviata', ['template' => $label, 'to_count' => count($email->to)]);

            return true;
        } catch (MailException $e) {
            $this->logger->error('Email non inviata', ['template' => $label, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
