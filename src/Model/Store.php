<?php

declare(strict_types=1);

namespace FomaPay\Model;

/** Boutique liée à la clé, avec les modes que son niveau KYC autorise. */
final class Store
{
    public readonly string $id;
    public readonly string $name;
    public readonly string $shortCode;
    public readonly ?string $logoUrl;
    public readonly bool $livemode;
    public readonly int $kycLevel;
    /** @var list<string> */
    public readonly array $paymentModes;

    /** @param array<string, mixed> $raw */
    public function __construct(public readonly array $raw)
    {
        $this->id = (string) ($raw['id'] ?? '');
        $this->name = (string) ($raw['name'] ?? '');
        $this->shortCode = (string) ($raw['shortCode'] ?? '');
        $this->logoUrl = $raw['logoUrl'] ?? null;
        $this->livemode = (bool) ($raw['livemode'] ?? false);
        $this->kycLevel = (int) ($raw['kycLevel'] ?? 0);
        $this->paymentModes = $raw['paymentModes'] ?? [];
    }
}
