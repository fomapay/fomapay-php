<?php

declare(strict_types=1);

namespace FomaPay\Http;

use FomaPay\Exception\NetworkException;

/** Transport HTTP du SDK. Implémentez-le pour brancher Guzzle, Symfony HttpClient ou un faux client de test. */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     * @param float                 $timeout Délai maximal, en secondes.
     *
     * @throws NetworkException quand aucune réponse n'a été reçue
     */
    public function request(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse;
}
