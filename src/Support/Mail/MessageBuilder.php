<?php

declare(strict_types=1);

namespace App\Support\Mail;

use PHPMailer\PHPMailer\PHPMailer;

/** Costruisce il messaggio MIME con PHPMailer (usato sia dal trasporto SMTP sia da quello "log"). */
final class MessageBuilder
{
    public function __construct(
        private readonly string $fromEmail,
        private readonly string $fromName,
    ) {
    }

    public function build(Email $email, ?PHPMailer $mailer = null): PHPMailer
    {
        $m = $mailer ?? new PHPMailer(true);
        $m->CharSet = PHPMailer::CHARSET_UTF8;
        $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $m->XMailer = ' ';
        $m->setFrom($this->fromEmail, $this->fromName);
        foreach ($email->to as $to) {
            $m->addAddress($to);
        }
        if ($email->replyTo !== null && $email->replyTo !== '') {
            $m->addReplyTo($email->replyTo);
        }
        $m->Subject = $email->subject;
        $m->isHTML(true);
        $m->Body = $email->html;
        $m->AltBody = $email->text;
        foreach ($email->attachments as $attachment) {
            $m->addStringAttachment($attachment['content'], $attachment['name'], PHPMailer::ENCODING_BASE64, $attachment['mime']);
        }

        return $m;
    }
}
