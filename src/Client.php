<?php

declare(strict_types=1);

namespace FomaPay;

use FomaPay\Exception\ApiException;
use FomaPay\Exception\ConfigurationException;
use FomaPay\Exception\FomaPayException;
use FomaPay\Exception\NetworkException;
use FomaPay\Http\CurlHttpClient;
use FomaPay\Http\HttpClientInterface;
use FomaPay\Resource\Balance;
use FomaPay\Resource\Payments;
use FomaPay\Resource\Payouts;
use FomaPay\Resource\Store;

/**
 * Client serveur FomaPay.
 *
 *     $fomapay = new \FomaPay\Client('sk_test_…');
 *     $payment = $fomapay->payments()->create(['amount' => 25, 'currency' => 'USD', 'orderId' => 'CMD-8921']);
 */
final class Client
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://api.fomapay.cd';
    public const DEFAULT_TIMEOUT = 30.0;
    public const DEFAULT_MAX_RETRIES = 2;
    private const MAX_BACKOFF = 8.0;

    private readonly string $baseUrl;
    private readonly float $timeout;
    private readonly int $maxRetries;
    private readonly HttpClientInterface $http;
    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    private readonly Payments $payments;
    private readonly Payouts $payouts;
    private readonly Balance $balance;
    private readonly Store $store;

    /**
     * @param array{
     *     base_url?: string,
     *     timeout?: float,
     *     max_retries?: int,
     *     http_client?: HttpClientInterface,
     *     sleep?: callable(float): void,
     * } $options timeout en secondes (30 par défaut) ; max_retries : 2 par défaut ; sleep : réservé aux tests
     */
    public function __construct(private readonly string $secretKey, array $options = [])
    {
        if (!str_starts_with($secretKey, 'sk_test_') && !str_starts_with($secretKey, 'sk_live_')) {
            throw new ConfigurationException(
                'ERROR_INVALID_API_KEY',
                'Clé secrète absente ou mal formée : elle commence par sk_test_ ou sk_live_ (FomaPay Business › Développeurs).',
            );
        }
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->timeout = (float) ($options['timeout'] ?? self::DEFAULT_TIMEOUT);
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? self::DEFAULT_MAX_RETRIES));
        $this->http = $options['http_client'] ?? new CurlHttpClient();
        $this->sleep = ($options['sleep'] ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        })(...);

        $this->payments = new Payments($this);
        $this->payouts = new Payouts($this);
        $this->balance = new Balance($this);
        $this->store = new Store($this);
    }

    public function payments(): Payments
    {
        return $this->payments;
    }

    /** Alias de payments(). */
    public function transactions(): Payments
    {
        return $this->payments;
    }

    public function payouts(): Payouts
    {
        return $this->payouts;
    }

    public function balance(): Balance
    {
        return $this->balance;
    }

    public function store(): Store
    {
        return $this->store;
    }

    public function isLiveMode(): bool
    {
        return str_starts_with($this->secretKey, 'sk_live_');
    }

    /** Clé unique : rejouer la requête ne crée pas de doublon. */
    public static function newIdempotencyKey(): string
    {
        return 'php-' . bin2hex(random_bytes(16));
    }

    /**
     * Exécute une requête, avec nouvelles tentatives quand elle est rejouable sans risque
     * (lecture, ou POST accompagné d'une clé d'idempotence).
     *
     * @param array<string, mixed>|null $body
     * @param array<string, scalar>     $query
     *
     * @return array<string, mixed>
     *
     * @throws FomaPayException
     *
     * @internal
     */
    public function request(string $method, string $path, ?array $body = null, array $query = [], ?string $idempotencyKey = null): array
    {
        $url = $this->baseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query));
        $headers = [
            'Authorization' => 'Bearer ' . $this->secretKey,
            'Accept' => 'application/json',
            'User-Agent' => 'fomapay-php/' . self::VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }
        $replayable = $method === 'GET' || $idempotencyKey !== null;

        for ($attempt = 0; ; ++$attempt) {
            $wait = null;
            try {
                $response = $this->http->request($method, $url, $headers, $payload, $this->timeout);
                if ($response->status >= 200 && $response->status < 300) {
                    return self::decode($response->body, $response->status);
                }
                $error = self::apiError($response->status, $response->body);
                if ($response->status === 429) {
                    // La gateway a refusé la requête avant tout traitement : toujours rejouable.
                    $retry = true;
                    $retryAfter = (int) ($response->header('Retry-After') ?? 0);
                    $wait = $retryAfter > 0 ? min((float) $retryAfter, self::MAX_BACKOFF) : null;
                } else {
                    $retry = $response->status >= 500 && $replayable;
                }
            } catch (NetworkException $error) {
                $retry = $replayable;
            }

            if (!$retry || $attempt >= $this->maxRetries) {
                throw $error;
            }
            ($this->sleep)($wait ?? self::backoff($attempt));
        }
    }

    /** 0,5 s, 1 s, 2 s… plafonné à 8 s, avec un aléa pour désynchroniser les clients. */
    private static function backoff(int $attempt): float
    {
        $delay = min(0.5 * (2 ** $attempt), self::MAX_BACKOFF);

        return $delay / 2 + (mt_rand() / mt_getrandmax()) * ($delay / 2);
    }

    /** @return array<string, mixed> */
    private static function decode(string $body, int $status): array
    {
        if ($body === '') {
            return [];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new ApiException('ERROR_INVALID_RESPONSE', "Réponse illisible de l'API FomaPay.", $status);
        }

        return $data;
    }

    private static function apiError(int $status, string $body): ApiException
    {
        $data = json_decode($body, true);
        $error = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];

        return new ApiException(
            (string) ($error['code'] ?? 'ERROR_UNKNOWN'),
            (string) ($error['message'] ?? 'Erreur HTTP ' . $status),
            $status,
            is_array($error['details'] ?? null) ? $error['details'] : [],
        );
    }
}
