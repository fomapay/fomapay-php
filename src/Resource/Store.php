<?php

declare(strict_types=1);

namespace FomaPay\Resource;

use FomaPay\Client;
use FomaPay\Model\Store as StoreModel;

final class Store
{
    public function __construct(private readonly Client $client)
    {
    }

    public function retrieve(): StoreModel
    {
        return new StoreModel($this->client->request('GET', '/v1/store'));
    }
}
