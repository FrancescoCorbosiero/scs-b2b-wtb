<?php

declare(strict_types=1);

namespace App\Support\Mail;

/** Sviluppo: ogni email diventa un file .eml in var/mail/ (docs/02). */
final class LogTransport implements MailTransport
{
    public function __construct(
        private readonly MessageBuilder $builder,
        private readonly string $directory,
    ) {
    }

    public function send(Email $email): void
    {
        try {
            $mailer = $this->builder->build($email);
            $mailer->preSend();
            $mime = $mailer->getSentMIMEMessage();
        } catch (\Throwable $e) {
            throw new MailException('Email non costruita: ' . $e->getMessage(), 0, $e);
        }
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
        $name = sprintf('%s-%s.eml', gmdate('Ymd-His'), bin2hex(random_bytes(3)));
        if (file_put_contents($this->directory . '/' . $name, $mime) === false) {
            throw new MailException('Impossibile scrivere ' . $name);
        }
    }
}
