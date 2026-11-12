<?php

declare(strict_types=1);

namespace App\Support\Mail;

/** Test: tiene le email in memoria; può simulare un SMTP spento. */
final class ArrayTransport implements MailTransport
{
    /** @var list<Email> */
    public array $sent = [];

    public bool $failing = false;

    public function send(Email $email): void
    {
        if ($this->failing) {
            throw new MailException('SMTP spento (simulato).');
        }
        $this->sent[] = $email;
    }

    /** @return list<Email> */
    public function sentTo(string $address): array
    {
        return array_values(array_filter(
            $this->sent,
            static fn (Email $e): bool => in_array(strtolower($address), array_map('strtolower', $e->to), true),
        ));
    }
}
