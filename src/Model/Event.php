<?php

declare(strict_types=1);

namespace FomaPay\Model;

/** Événement de webhook : payment.succeeded, payment.failed, payout.succeeded… */
final class Event
{
    public readonly string $id;
    public readonly string $type;
    public readonly string $created;
    public readonly bool $livemode;
    /** Le paiement (ou retrait) concerné. */
    public readonly Payment $data;

    /** @param array<string, mixed> $raw */
    public function __construct(public readonly array $raw)
    {
        $this->id = (string) ($raw['id'] ?? '');
        $this->type = (string) ($raw['type'] ?? '');
        $this->created = (string) ($raw['created'] ?? '');
        $this->livemode = (bool) ($raw['livemode'] ?? false);
        $this->data = new Payment(is_array($raw['data'] ?? null) ? $raw['data'] : []);
    }
}
