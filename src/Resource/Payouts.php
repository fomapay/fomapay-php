<?php

declare(strict_types=1);

namespace FomaPay\Resource;

use FomaPay\Client;
use FomaPay\Model\Payment;

final class Payouts
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Retrait du solde de la boutique vers un compte Mobile Money.
     * Jamais rejoué automatiquement après une erreur réseau : un retrait ne doit pas partir deux fois.
     *
     * @param array{amount: int|float, currency: string, operator: string, phone: string, description?: string} $params
     */
    public function create(array $params): Payment
    {
        return new Payment($this->client->request('POST', '/v1/payouts', $params));
    }
}
