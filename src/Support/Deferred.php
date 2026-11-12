<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Log\LoggerInterface;

/**
 * Lavori rinviati a dopo la risposta HTTP (es. le email dei webhook: il fornitore vuole un 2xx entro 10 s).
 * In CLI e nei test si eseguono chiamando run().
 */
final class Deferred
{
    /** @var list<array{label: string, task: callable(): void}> */
    private array $tasks = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function add(string $label, callable $task): void
    {
        $this->tasks[] = ['label' => $label, 'task' => $task];
    }

    public function count(): int
    {
        return count($this->tasks);
    }

    public function run(): void
    {
        while ($item = array_shift($this->tasks)) {
            try {
                ($item['task'])();
            } catch (\Throwable $e) {
                $this->logger->error('Lavoro rinviato non riuscito', ['task' => $item['label'], 'error' => $e->getMessage()]);
            }
        }
    }
}
