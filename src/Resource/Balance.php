<?php

declare(strict_types=1);

namespace FomaPay\Resource;

use FomaPay\Client;
use FomaPay\Model\Balance as BalanceModel;

final class Balance
{
    public function __construct(private readonly Client $client)
    {
    }

    /** @return list<BalanceModel> Un solde par devise. */
    public function retrieve(): array
    {
        $data = $this->client->request('GET', '/v1/balance');

        return array_map(static fn (array $item): BalanceModel => new BalanceModel($item), $data['data'] ?? []);
    }
}
