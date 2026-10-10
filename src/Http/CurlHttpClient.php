<?php

declare(strict_types=1);

namespace FomaPay\Http;

use FomaPay\Exception\NetworkException;

/** Transport par défaut, basé sur l'extension cURL. */
final class CurlHttpClient implements HttpClientInterface
{
    public function request(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $responseHeaders = [];
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) round(min($timeout, 10) * 1000),
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($curl);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($result === false) {
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new NetworkException('ERROR_TIMEOUT', "FomaPay n'a pas répondu dans le délai imparti.");
            }
            throw new NetworkException('ERROR_NETWORK', 'Impossible de joindre FomaPay : ' . $error);
        }

        return new HttpResponse($status, (string) $result, $responseHeaders);
    }
}
