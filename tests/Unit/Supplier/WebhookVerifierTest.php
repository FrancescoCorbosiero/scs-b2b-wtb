<?php

declare(strict_types=1);

namespace Tests\Unit\Supplier;

use App\Supplier\Wtb\WebhookVerifier;
use App\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class WebhookVerifierTest extends TestCase
{
    public function testSignatureRules(): void
    {
        $clock = new FrozenClock('2026-11-12T10:00:00Z');
        $now = $clock->timestamp();
        $verifier = new WebhookVerifier('whsec_x', $clock);
        $body = '{"id":1,"event":"ping"}';

        self::assertTrue($verifier->verify(WebhookVerifier::sign('whsec_x', $now, $body), $body));
        self::assertTrue($verifier->verify(WebhookVerifier::sign('whsec_x', $now - 299, $body), $body));
        self::assertFalse($verifier->verify(WebhookVerifier::sign('whsec_x', $now - 301, $body), $body), 'Più vecchia di 5 minuti');
        self::assertFalse($verifier->verify(WebhookVerifier::sign('whsec_y', $now, $body), $body), 'Segreto sbagliato');
        self::assertFalse($verifier->verify(WebhookVerifier::sign('whsec_x', $now, $body), $body . ' '), 'Corpo modificato');
        self::assertFalse($verifier->verify('v1=abc', $body));
        self::assertFalse($verifier->verify('', $body));
        $multi = 't=' . $now . ',v1=deadbeef,v1=' . substr(WebhookVerifier::sign('whsec_x', $now, $body), strlen('t=' . $now . ',v1='));
        self::assertTrue($verifier->verify($multi, $body), 'Più firme v1: ne basta una valida');
        self::assertFalse((new WebhookVerifier('', $clock))->verify(WebhookVerifier::sign('', $now, $body), $body), 'Senza segreto non si accetta nulla');
    }
}
