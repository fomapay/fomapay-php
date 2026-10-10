<?php

declare(strict_types=1);

namespace FomaPay\Model;

/** Un paiement, un retrait ou tout autre mouvement. */
final class Payment
{
    public readonly string $id;
    public readonly string $reference;
    /** pending, processing, success, failed ou expired. */
    public readonly string $status;
    public readonly float $amount;
    public readonly float $fee;
    public readonly float $net;
    public readonly string $currency;
    public readonly ?string $channel;
    public readonly ?string $orderId;
    public readonly ?string $description;
    /** @var array<string, mixed>|null */
    public readonly ?array $customer;
    /** @var array<string, mixed>|null */
    public readonly ?array $metadata;
    /** @var list<string> */
    public readonly array $paymentModes;
    public readonly ?string $callbackUrl;
    public readonly ?string $payerPhone;
    /** Contenu du QR code à afficher au client (FomaPay Direct). */
    public readonly ?string $qrCode;
    public readonly bool $livemode;
    public readonly ?string $storeId;
    public readonly ?string $failureCode;
    public readonly ?string $failureReason;
    public readonly ?string $expiresAt;
    public readonly ?string $completedAt;
    public readonly ?string $createdAt;

    /** @param array<string, mixed> $raw Réponse JSON complète de l'API. */
    public function __construct(public readonly array $raw)
    {
        $this->id = (string) ($raw['id'] ?? '');
        $this->reference = (string) ($raw['reference'] ?? '');
        $this->status = (string) ($raw['status'] ?? '');
        $this->amount = (float) ($raw['amount'] ?? 0);
        $this->fee = (float) ($raw['fee'] ?? 0);
        $this->net = (float) ($raw['net'] ?? 0);
        $this->currency = (string) ($raw['currency'] ?? '');
        $this->channel = $raw['channel'] ?? null;
        $this->orderId = $raw['orderId'] ?? null;
        $this->description = $raw['description'] ?? null;
        $this->customer = $raw['customer'] ?? null;
        $this->metadata = $raw['metadata'] ?? null;
        $this->paymentModes = $raw['paymentModes'] ?? [];
        $this->callbackUrl = $raw['callbackUrl'] ?? null;
        $this->payerPhone = $raw['payerPhone'] ?? null;
        $this->qrCode = $raw['qrCode'] ?? null;
        $this->livemode = (bool) ($raw['livemode'] ?? false);
        $this->storeId = $raw['storeId'] ?? null;
        $this->failureCode = $raw['failureCode'] ?? null;
        $this->failureReason = $raw['failureReason'] ?? null;
        $this->expiresAt = $raw['expiresAt'] ?? null;
        $this->completedAt = $raw['completedAt'] ?? null;
        $this->createdAt = $raw['createdAt'] ?? null;
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }

    /** Le paiement attend encore le client ou l'opérateur. */
    public function isPending(): bool
    {
        return $this->status === 'pending' || $this->status === 'processing';
    }
}
