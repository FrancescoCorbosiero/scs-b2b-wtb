<?php

declare(strict_types=1);

namespace App\Support\Mail;

interface MailTransport
{
    /** @throws MailException */
    public function send(Email $email): void;
}
