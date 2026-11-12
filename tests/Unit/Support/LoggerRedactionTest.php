<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\LoggerFactory;
use PHPUnit\Framework\TestCase;

final class LoggerRedactionTest extends TestCase
{
    public function testSecretsNeverReachTheLog(): void
    {
        $text = LoggerFactory::redact('X-API-Key: wtb_test_abc123DEF456 whsec_s3cr3t sig t=1,v1=' . str_repeat('ab', 32) . ' /x?token=' . str_repeat('f', 64));
        self::assertStringNotContainsString('abc123DEF456', $text);
        self::assertStringNotContainsString('s3cr3t', $text);
        self::assertStringNotContainsString(str_repeat('ab', 32), $text);
        self::assertStringNotContainsString(str_repeat('f', 64), $text);
    }
}
