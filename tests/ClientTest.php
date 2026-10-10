<?php

declare(strict_types=1);

namespace FomaPay\Tests;

use FomaPay\Client;
use FomaPay\Exception\ApiException;
use FomaPay\Exception\ConfigurationException;
use FomaPay\Exception\NetworkException;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const PAYMENT = ['id' => 'pay_1', 'reference' => 'FP-1', 'status' => 'pending', 'amount' => 25, 'currency' => 'USD', 'orderId' => 'CMD-1'];
    private const PAYOUT = ['amount' => 10, 'currency' => 'USD', 'operator' => 'mpesa', 'phone' => '+243810000000'];

    /** @var list<float> */
    private array $waits = [];

    private function client(FakeHttpClient $http, array $options = []): Client
    {
        $this->waits = [];

        return new Client('sk_test_unit', $options + [
            'base_url' => 'https://api.test/',
            'http_client' => $http,
            'sleep' => function (float $seconds): void {
                $this->waits[] = $seconds;
            },
        ]);
    }

    public function testRejectsMalformedSecretKey(): void
    {
        $this->expectException(ConfigurationException::class);
        new Client('pk_test_abc');
    }

    public function testCreateSendsStandardFormatAndIdempotencyKey(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(201, self::PAYMENT)]);
        $params = [
            'amount' => 25,
            'currency' => 'USD',
            'orderId' => 'CMD-1',
            'customer' => ['name' => 'Jean Marc', 'phone' => '+243810000000'],
            'paymentModes' => ['fomapay_direct', 'mobile_money'],
            'callbackUrl' => 'https://marchand.cd/api/callback',
            'metadata' => ['custom_field' => 'valeur'],
        ];

        $payment = $this->client($http)->transactions()->create($params);

        $request = $http->requests[0];
        self::assertSame(['POST', 'https://api.test/v1/payments'], [$request['method'], $request['url']]);
        self::assertSame('Bearer sk_test_unit', $request['headers']['Authorization']);
        self::assertStringStartsWith('php-', $request['headers']['Idempotency-Key']);
        self::assertSame($params, json_decode((string) $request['body'], true));
        self::assertSame(30.0, $request['timeout']);
        self::assertSame('CMD-1', $payment->orderId);
        self::assertTrue($payment->isPending());
        self::assertFalse($payment->isSuccessful());
    }

    public function testRetriesServerErrorsWithTheSameIdempotencyKey(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::error(503, 'ERROR_UNAVAILABLE'),
            new NetworkException('ERROR_NETWORK', 'coupure'),
            FakeHttpClient::json(201, self::PAYMENT),
        ]);

        self::assertSame('pay_1', $this->client($http)->payments()->create(['amount' => 25, 'currency' => 'USD'])->id);
        self::assertCount(3, $http->requests);
        self::assertCount(1, array_unique(array_map(static fn (array $r): string => $r['headers']['Idempotency-Key'], $http->requests)));
        self::assertCount(2, $this->waits);
        self::assertTrue($this->waits[0] >= 0.25 && $this->waits[0] <= 0.5 && $this->waits[1] >= 0.5 && $this->waits[1] <= 1.0);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::error(500, 'ERROR_INTERNAL')]);
        try {
            $this->client($http, ['max_retries' => 1])->payments()->retrieve('FP-1');
            self::fail('une ApiException était attendue');
        } catch (ApiException $e) {
            self::assertSame([500, 'ERROR_INTERNAL'], [$e->getStatus(), $e->getErrorCode()]);
        }
        self::assertCount(2, $http->requests);
    }

    public function testClientErrorsAreNotRetried(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::error(403, 'ERROR_LEVEL_0_LIMIT_REACHED')]);
        try {
            $this->client($http)->payments()->create(['amount' => 900, 'currency' => 'USD']);
            self::fail('une ApiException était attendue');
        } catch (ApiException $e) {
            self::assertSame('ERROR_LEVEL_0_LIMIT_REACHED', $e->getErrorCode());
            self::assertSame(['champ' => 'amount'], $e->getDetails());
        }
        self::assertCount(1, $http->requests);
    }

    public function testPayoutsAreNeverReplayed(): void
    {
        foreach ([FakeHttpClient::error(502, 'ERROR_BAD_GATEWAY'), new NetworkException('ERROR_TIMEOUT', 'trop long')] as $failure) {
            $http = new FakeHttpClient([$failure]);
            try {
                $this->client($http)->payouts()->create(self::PAYOUT);
                self::fail('une exception était attendue');
            } catch (ApiException|NetworkException) {
            }
            self::assertCount(1, $http->requests);
            self::assertArrayNotHasKey('Idempotency-Key', $http->requests[0]['headers']);
        }
    }

    public function testRateLimitIsAlwaysRetriedAndHonoursRetryAfter(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::error(429, 'ERROR_RATE_LIMITED', ['retry-after' => '2']),
            FakeHttpClient::json(201, self::PAYMENT),
        ]);
        $this->client($http)->payouts()->create(self::PAYOUT);
        self::assertCount(2, $http->requests);
        self::assertSame([2.0], $this->waits);
    }

    public function testOtherResources(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(200, ['data' => [self::PAYMENT]]),
            FakeHttpClient::json(200, ['status' => 'processing'] + self::PAYMENT),
            FakeHttpClient::json(200, ['status' => 'success'] + self::PAYMENT),
            FakeHttpClient::json(200, ['data' => [['currency' => 'USD', 'available' => 12.5]]]),
            FakeHttpClient::json(200, ['id' => 's1', 'name' => 'Chez Foma', 'shortCode' => 'FOMA', 'kycLevel' => 2, 'paymentModes' => ['fomapay_direct']]),
        ]);
        $client = $this->client($http);

        self::assertCount(1, $client->payments()->list(['limit' => 5, 'status' => 'success']));
        self::assertSame('processing', $client->payments()->payWithMobileMoney('FP/1', 'airtel_money', '+243970000000')->status);
        self::assertTrue($client->payments()->simulate('pay_1')->isSuccessful());
        self::assertSame(12.5, $client->balance()->retrieve()[0]->available);
        self::assertSame(['FOMA', 2], [$client->store()->retrieve()->shortCode, $client->store()->retrieve()->kycLevel]);

        self::assertSame('https://api.test/v1/payments?limit=5&status=success', $http->requests[0]['url']);
        self::assertSame('https://api.test/v1/payments/FP%2F1/mobile-money', $http->requests[1]['url']);
        self::assertSame(['outcome' => 'success'], json_decode((string) $http->requests[2]['body'], true));
        self::assertFalse($client->isLiveMode());
    }
}
