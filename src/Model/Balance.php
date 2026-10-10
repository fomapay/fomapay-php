<?php

declare(strict_types=1);

namespace FomaPay\Model;

/** Solde disponible de la boutique dans une devise. */
final class Balance
{
    public readonly string $currency;
    public readonly float $available;

    /** @param array<string, mixed> $raw */
    public function __construct(public readonly array $raw)
    {
        $this->currency = (string) ($raw['currency'] ?? '');
        $this->available = (float) ($raw['available'] ?? 0);
    }
}
