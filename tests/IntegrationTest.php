<?php

declare(strict_types=1);

namespace FomaPay\Tests;

use FomaPay\Client;
use FomaPay\Exception\ApiException;
use PHPUnit\Framework\TestCase;

/**
 * Tests d'intégration contre le sandbox FomaPay. Ils ne s'exécutent que si
 * FOMAPAY_TEST_SECRET_KEY (sk_test_…) est définie ; FOMAPAY_API_URL cible un backend local :
 *
 *     FOMAPAY_API_URL=http://localhost:8080 FOMAPAY_TEST_SECRET_KEY=sk_test_… composer test
 */
final class IntegrationTest extends TestCase
{
    public function testSandbox(): void
    {
        $key = getenv('FOMAPAY_TEST_SECRET_KEY') ?: '';
        if ($key === '') {
            self::markTestSkipped('FOMAPAY_TEST_SECRET_KEY non définie');
        }
        $client = new Client($key, array_filter(['base_url' => getenv('FOMAPAY_API_URL') ?: null]));

        $store = $client->store()->retrieve();
        self::assertFalse($store->livemode);
        self::assertCount(2, $store->paymentModes);

        // Format standard d'une transaction, dans les deux modes.
        $payment = $client->payments()->create([
            'amount' => 1000,
            'currency' => 'CDF',
            'orderId' => 'CMD-2026-001',
            'customer' => ['name' => 'Jean Marc', 'phone' => '+243810000000', 'email' => 'jean@example.com'],
            'paymentModes' => ['fomapay_direct', 'mobile_money'],
            'callbackUrl' => 'https://marchand.cd/api/callback',
            'metadata' => ['custom_field' => 'valeur'],
        ]);
        self::assertSame('pending', $payment->status);
        self::assertSame('https://marchand.cd/api/callback', $payment->callbackUrl);
        self::assertNotEmpty($payment->qrCode);

        // Mode Mobile Money : l'opérateur simulé confirme après ~3 s.
        $final = $client->payments()->payWithMobileMoney($payment->id, 'airtel_money', '+243970000000');
        self::assertSame('processing', $final->status);
        for ($deadline = time() + 10; $final->isPending();) {
            self::assertLessThan($deadline, time(), "le paiement Mobile Money n'a pas été confirmé à temps");
            sleep(1);
            $final = $client->payments()->retrieve($payment->reference);
        }
        self::assertSame(['success', 'airtel_money'], [$final->status, $final->channel]);

        // Mode FomaPay Direct : issue forcée en sandbox.
        $direct = $client->payments()->create(['amount' => 12.5, 'currency' => 'USD', 'paymentModes' => ['fomapay_direct']]);
        self::assertTrue($client->payments()->simulate($direct->id, 'success')->isSuccessful());

        // Idempotence : la même clé renvoie le même paiement.
        $first = $client->payments()->create(['amount' => 3, 'currency' => 'USD'], 'php-it-' . $payment->id);
        $again = $client->payments()->create(['amount' => 3, 'currency' => 'USD'], 'php-it-' . $payment->id);
        self::assertSame($first->id, $again->id);

        self::assertNotEmpty($client->payments()->list(['limit' => 5, 'status' => 'success']));
        self::assertNotEmpty($client->balance()->retrieve());

        foreach ([['ERROR_VALIDATION', fn () => $client->payments()->create(['amount' => -1, 'currency' => 'USD'])],
            ['ERROR_NOT_FOUND', fn () => $client->payments()->retrieve('FP-INEXISTANT')]] as [$code, $call]) {
            try {
                $call();
                self::fail("$code était attendu");
            } catch (ApiException $e) {
                self::assertSame($code, $e->getErrorCode());
            }
        }
    }
}
