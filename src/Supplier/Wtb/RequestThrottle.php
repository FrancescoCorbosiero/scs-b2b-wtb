<?php

declare(strict_types=1);

namespace App\Supplier\Wtb;

/**
 * Limite per processo: al massimo ~100 richieste al minuto (margine sotto le 120 del fornitore, docs/03).
 */
final class RequestThrottle
{
    /** @var list<float> */
    private array $sent = [];

    /** @param callable(float): void $sleep */
    public function __construct(
        private readonly int $maxPerMinute,
        private readonly mixed $sleep,
        private readonly mixed $now = null,
    ) {
    }

    public function wait(): void
    {
        $now = $this->now();
        $this->sent = array_values(array_filter($this->sent, static fn (float $t): bool => $t > $now - 60.0));
        if (count($this->sent) >= $this->maxPerMinute) {
            $delay = $this->sent[0] + 60.0 - $now;
            if ($delay > 0) {
                ($this->sleep)($delay);
            }
            array_shift($this->sent);
        }
        $this->sent[] = $this->now();
    }

    private function now(): float
    {
        return $this->now === null ? microtime(true) : (float) ($this->now)();
    }
}
