<?php

declare(strict_types=1);

namespace Tests\Unit\Accounts;

use App\Accounts\Passwords;
use App\Accounts\VatNumber;
use PHPUnit\Framework\TestCase;

final class VatNumberTest extends TestCase
{
    public function testFormatsByCountry(): void
    {
        self::assertSame('IT01234567897', VatNumber::normalize('01234567897', 'IT'));
        self::assertSame('IT01234567897', VatNumber::normalize('it 012 345 678 97', 'IT'));
        self::assertNull(VatNumber::normalize('01234567890', 'IT'), 'Cifra di controllo sbagliata');
        self::assertSame('DE123456789', VatNumber::normalize('DE 123456789', 'DE'));
        self::assertSame('FRXX123456789', VatNumber::normalize('FRXX123456789', 'FR'));
        self::assertSame('EL123456789', VatNumber::normalize('123456789', 'GR'));
        self::assertSame('NL123456789B01', VatNumber::normalize('NL123456789B01', 'NL'));
        self::assertSame('GB123456789', VatNumber::normalize('GB123456789', 'GB'));
        self::assertSame('CHE123456789MWST', VatNumber::normalize('CHE-123.456.789 MWST', 'CH'));
        self::assertNull(VatNumber::normalize('12345', 'DE'));
    }

    public function testPasswordPolicy(): void
    {
        self::assertSame('auth.password_too_short', Passwords::policyError('short', 'short'));
        self::assertSame('auth.password_mismatch', Passwords::policyError('long-enough-1', 'long-enough-2'));
        self::assertNull(Passwords::policyError('long-enough-1', 'long-enough-1'));
        $hash = Passwords::hash('long-enough-1');
        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue(Passwords::verify('long-enough-1', $hash));
        self::assertFalse(Passwords::verify('long-enough-1', null));
    }
}
