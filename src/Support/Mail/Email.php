<?php

declare(strict_types=1);

namespace App\Support\Mail;

final class Email
{
    /**
     * @param list<string> $to
     * @param list<array{name: string, content: string, mime: string}> $attachments
     */
    public function __construct(
        public readonly array $to,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly ?string $replyTo = null,
        public readonly array $attachments = [],
    ) {
    }
}
