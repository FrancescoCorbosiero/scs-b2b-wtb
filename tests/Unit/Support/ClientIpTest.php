<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ClientIp;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase
{
    public function testForwardedHeadersAreIgnoredFromUntrustedPeers(): void
    {
        $ip = new ClientIp(['172.18.0.0/16']);
        self::assertSame('198.51.100.7', $ip->resolve(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']));
        self::assertFalse($ip->isHttps(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_PROTO' => 'https']));
    }

    public function testRightmostUntrustedAddressFromTrustedProxy(): void
    {
        $ip = new ClientIp(['172.18.0.0/16', '10.0.0.0/8']);
        self::assertSame('203.0.113.9', $ip->resolve([
            'REMOTE_ADDR' => '172.18.0.3',
            'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.9, 10.1.2.3',
        ]));
        self::assertTrue($ip->isHttps(['REMOTE_ADDR' => '172.18.0.3', 'HTTP_X_FORWARDED_PROTO' => 'https']));
    }

    public function testCidrMatching(): void
    {
        self::assertTrue(ClientIp::inCidr('192.168.1.200', '192.168.1.0/24'));
        self::assertFalse(ClientIp::inCidr('192.168.2.1', '192.168.1.0/24'));
        self::assertTrue(ClientIp::inCidr('172.20.5.5', '172.16.0.0/12'));
        self::assertTrue(ClientIp::inCidr('2001:db8::1', '2001:db8::/32'));
        self::assertFalse(ClientIp::inCidr('2001:db9::1', '2001:db8::/32'));
        self::assertFalse(ClientIp::inCidr('10.0.0.1', '2001:db8::/32'));
        self::assertTrue(ClientIp::inCidr('10.0.0.1', '10.0.0.1'));
    }
}
