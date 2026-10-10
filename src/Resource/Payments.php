<?php

declare(strict_types=1);

namespace FomaPay\Resource;

use FomaPay\Client;
use FomaPay\Model\Payment;

final class Payments
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Crée une demande de paiement.
     *
     * @param array{
     *     amount: int|float,
     *     currency: string,
     *     orderId?: string,
     *     description?: string,
     *     customer?: array{name?: string, phone?: string, email?: string},
     *     paymentModes?: list<string>,
     *     callbackUrl?: string,
     *     metadata?: array<string, mixed>,
     * } $params
     * @param string|null $idempotencyKey ex. l'identifiant de commande : un appel répété renvoie le même paiement
     */
    public function create(array $params, ?string $idempotencyKey = null): Payment
    {
        return new Payment($this->client->request('POST', '/v1/payments', $params, [], $idempotencyKey ?? Client::newIdempotencyKey()));
    }

    /** Relit un paiement par son identifiant ou sa référence (FP-…). */
    public function retrieve(string $id): Payment
    {
        return new Payment($this->client->request('GET', '/v1/payments/' . rawurlencode($id)));
    }

    /**
     * @param array{limit?: int, status?: string} $params
     *
     * @return list<Payment>
     */
    public function list(array $params = []): array
    {
        $data = $this->client->request('GET', '/v1/payments', null, $params);

        return array_map(static fn (array $item): Payment => new Payment($item), $data['data'] ?? []);
    }

    /** Envoie la demande de débit (push USSD) sur le téléphone du client. */
    public function payWithMobileMoney(string $id, string $operator, string $phone): Payment
    {
        return new Payment($this->client->request(
            'POST',
            '/v1/payments/' . rawurlencode($id) . '/mobile-money',
            ['operator' => $operator, 'phone' => $phone],
        ));
    }

    /** Force l'issue d'un paiement (success ou failed). Sandbox uniquement. */
    public function simulate(string $id, string $outcome = 'success'): Payment
    {
        return new Payment($this->client->request('POST', '/v1/payments/' . rawurlencode($id) . '/simulate', ['outcome' => $outcome]));
    }
}
