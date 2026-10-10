<?php

declare(strict_types=1);

namespace FomaPay\Tests;

use FomaPay\Http\HttpClientInterface;
use FomaPay\Http\HttpResponse;

/** Transport factice : rejoue une file de réponses (ou d'exceptions) et mémorise les requêtes. */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, timeout: float}> */
    public array $requests = [];

    /** @param list<HttpResponse|\Throwable> $queue */
    public function __construct(private array $queue)
    {
    }

    public function request(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body', 'timeout');
        $next = count($this->queue) > 1 ? array_shift($this->queue) : $this->queue[0];
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /** @param array<string, mixed> $data */
    public static function json(int $status, array $data, array $headers = []): HttpResponse
    {
        return new HttpResponse($status, json_encode($data, JSON_THROW_ON_ERROR), $headers);
    }

    public static function error(int $status, string $code, array $headers = []): HttpResponse
    {
        return self::json($status, ['error' => ['code' => $code, 'message' => 'oups', 'details' => ['champ' => 'amount']]], $headers);
    }
}
