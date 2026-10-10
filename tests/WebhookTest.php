<?php

declare(strict_types=1);

namespace FomaPay\Tests;

use FomaPay\Exception\ConfigurationException;
use FomaPay\Exception\SignatureException;
use FomaPay\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_unit';
    private const NOW = 1_800_000_000;
    private const BODY = '{"id":"evt_1","type":"payment.succeeded","created":"2026-10-10T10:00:00Z","livemode":false,"data":{"id":"pay_1","status":"success","orderId":"CMD-1"}}';

    private static function sign(string $body = self::BODY, string $secret = self::SECRET, int $timestamp = self::NOW): string
    {
        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public function testValidSignatureReturnsTheEvent(): void
    {
        $event = Webhook::constructEvent(self::BODY, self::sign(), self::SECRET, now: self::NOW);
        self::assertSame('payment.succeeded', $event->type);
        self::assertTrue($event->data->isSuccessful());
        self::assertSame('CMD-1', $event->data->orderId);
        self::assertTrue(Webhook::verify(self::BODY, self::sign(), self::SECRET, now: self::NOW + 299));
    }

    public function testKnownVector(): void
    {
        // Vecteur commun à tous les SDKs FomaPay, calculé avec OpenSSL :
        // printf '1700000000.{"id":"evt_1"}' | openssl dgst -sha256 -hmac whsec_test
        $header = 't=1700000000,v1=c89214b5b5da833daed6f0b8c5bb6bd58cea9022bd80ccc78230f3942d632925';
        self::assertTrue(Webhook::verify('{"id":"evt_1"}', $header, 'whsec_test', now: 1700000000));
    }

    /** @return iterable<string, array{string, ?string, int}> */
    public static function invalidSignatures(): iterable
    {
        yield 'corps modifié' => [self::BODY . ' ', self::sign(), self::NOW];
        yield 'mauvais secret' => [self::BODY, self::sign(secret: 'whsec_autre'), self::NOW];
        yield 'trop ancien' => [self::BODY, self::sign(), self::NOW + 301];
        yield 'dans le futur' => [self::BODY, self::sign(), self::NOW - 301];
        yield 'en-tête vide' => [self::BODY, '', self::NOW];
        yield 'en-tête absent' => [self::BODY, null, self::NOW];
        yield 'horodatage illisible' => [self::BODY, 't=abc,v1=00', self::NOW];
        yield 'sans v1' => [self::BODY, 't=' . self::NOW, self::NOW];
    }

    #[DataProvider('invalidSignatures')]
    public function testInvalidSignaturesAreRejected(string $body, ?string $header, int $now): void
    {
        self::assertFalse(Webhook::verify($body, $header, self::SECRET, now: $now));
        $this->expectException(SignatureException::class);
        Webhook::constructEvent($body, $header, self::SECRET, now: $now);
    }

    public function testMissingSecretIsAConfigurationError(): void
    {
        $this->expectException(ConfigurationException::class);
        Webhook::verify(self::BODY, self::sign(), '');
    }
}
