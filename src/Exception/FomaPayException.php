<?php

declare(strict_types=1);

namespace FomaPay\Exception;

/**
 * Erreur de base du SDK. Le code d'erreur est stable et exploitable par le programme
 * (ex. ERROR_LEVEL_0_LIMIT_REACHED) ; le message est lisible par un humain.
 */
class FomaPayException extends \RuntimeException
{
    /**
     * @param int                  $status  Statut HTTP (0 quand il n'y a pas eu de réponse).
     * @param array<string, mixed> $details
     */
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status = 0,
        private readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($errorCode . ': ' . $message, $status, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }
}
