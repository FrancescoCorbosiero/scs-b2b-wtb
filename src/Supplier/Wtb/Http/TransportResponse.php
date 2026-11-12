<?php

declare(strict_types=1);

namespace App\Supplier\Wtb\Http;

final class TransportResponse
{
    /** @param array<string, string> $headers nomi in minuscolo */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @param array<mixed> $data @param array<string, string> $headers */
    public static function json(int $status, array $data, array $headers = []): self
    {
        return new self($status, $headers + ['content-type' => 'application/json'], (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
