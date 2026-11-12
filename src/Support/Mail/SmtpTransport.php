<?php

declare(strict_types=1);

namespace App\Support\Mail;

use PHPMailer\PHPMailer\PHPMailer;

/** Produzione: SMTP (AWS SES). */
final class SmtpTransport implements MailTransport
{
    public function __construct(
        private readonly MessageBuilder $builder,
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $encryption,
    ) {
    }

    public function send(Email $email): void
    {
        if ($this->host === '') {
            throw new MailException('SMTP_HOST non impostato.');
        }
        try {
            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host = $this->host;
            $mailer->Port = $this->port;
            $mailer->Timeout = 20;
            if ($this->user !== '') {
                $mailer->SMTPAuth = true;
                $mailer->Username = $this->user;
                $mailer->Password = $this->password;
            }
            $mailer->SMTPSecure = match ($this->encryption) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            $mailer->SMTPAutoTLS = $this->encryption !== '';
            $this->builder->build($email, $mailer)->send();
        } catch (\Throwable $e) {
            throw new MailException('Invio SMTP non riuscito: ' . $e->getMessage(), 0, $e);
        }
    }
}
