<?php

declare(strict_types=1);

namespace App\Support;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Psr\Log\LoggerInterface;

/** Log in var/log/ (Monolog). Le chiavi, le firme e i token vengono sempre oscurati (docs/09). */
final class LoggerFactory
{
    public static function create(Config $config, string $channel = 'app'): LoggerInterface
    {
        $logger = new Logger($channel);
        $level = Level::fromName(ucfirst(strtolower($config->string('LOG_LEVEL', 'info'))));
        $logger->pushHandler(new RotatingFileHandler($config->varPath('log/' . $channel . '.log'), 30, $level));
        $logger->pushProcessor(static fn (LogRecord $record): LogRecord => self::redactRecord($record));

        return $logger;
    }

    public static function redactRecord(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::redact($record->message),
            context: self::redactArray($record->context),
        );
    }

    public static function redact(string $text): string
    {
        $patterns = [
            '/wtb_(test|live)_[A-Za-z0-9_\-]+/i' => 'wtb_$1_***',
            '/wtb_[A-Za-z0-9]{12,}/' => 'wtb_***',
            '/whsec_[A-Za-z0-9_\-]+/' => 'whsec_***',
            '/(x-api-key\s*[:=]\s*)\S+/i' => '$1***',
            '/(v1=)[a-f0-9]{16,}/i' => '$1***',
            '/(token=)[A-Za-z0-9]{16,}/i' => '$1***',
            '/(password["\']?\s*[:=]\s*)\S+/i' => '$1***',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/(api[_-]?key|secret|password|token|signature|authorization)/i', $key) === 1) {
                $data[$key] = '***';
            } elseif (is_string($value)) {
                $data[$key] = self::redact($value);
            } elseif (is_array($value)) {
                $data[$key] = self::redactArray($value);
            }
        }

        return $data;
    }
}
