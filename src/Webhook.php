<?php

declare(strict_types=1);

namespace FomaPay;

use FomaPay\Exception\ConfigurationException;
use FomaPay\Exception\SignatureException;
use FomaPay\Model\Event;

/** Vérification des webhooks FomaPay (HMAC-SHA256). */
final class Webhook
{
    /** En-tête HTTP qui porte la signature. */
    public const SIGNATURE_HEADER = 'X-FomaPay-Signature';
    /** Âge maximal accepté pour un événement, en secondes (anti-rejeu). */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Indique si la signature est valide pour ce corps.
     *
     * @param string      $payload corps BRUT de la requête : file_get_contents('php://input')
     * @param string|null $header  valeur de l'en-tête X-FomaPay-Signature : $_SERVER['HTTP_X_FOMAPAY_SIGNATURE']
     * @param string      $secret  secret de signature de l'endpoint (whsec_…)
     * @param int|null    $now     horodatage courant, réservé aux tests
     */
    public static function verify(string $payload, ?string $header, string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): bool
    {
        if ($secret === '') {
            throw new ConfigurationException('ERROR_WEBHOOK_SECRET_MISSING', 'Le secret de signature du webhook (whsec_…) est requis.');
        }

        $parts = [];
        foreach (explode(',', (string) $header) as $part) {
            if (str_contains($part, '=')) {
                [$name, $value] = explode('=', trim($part), 2);
                $parts[$name] = $value;
            }
        }
        $timestamp = $parts['t'] ?? '';
        $signature = $parts['v1'] ?? '';
        if (!ctype_digit($timestamp) || $signature === '') {
            return false;
        }

        // Comparaison en temps constant.
        if (!hash_equals(hash_hmac('sha256', $timestamp . '.' . $payload, $secret), strtolower($signature))) {
            return false;
        }

        // L'horodatage n'est contrôlé qu'après la signature : il est alors authentique.
        return abs(($now ?? time()) - (int) $timestamp) <= $tolerance;
    }

    /**
     * Vérifie la signature puis renvoie l'événement.
     *
     * @throws SignatureException si la signature est invalide ou expirée
     */
    public static function constructEvent(string $payload, ?string $header, string $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): Event
    {
        if (!self::verify($payload, $header, $secret, $tolerance, $now)) {
            throw new SignatureException('ERROR_INVALID_SIGNATURE', 'Signature de webhook invalide ou expirée.', 400);
        }
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            throw new SignatureException('ERROR_INVALID_PAYLOAD', "Le corps du webhook n'est pas un événement FomaPay valide.", 400);
        }

        return new Event($data);
    }
}
