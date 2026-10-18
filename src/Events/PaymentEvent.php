<?php

namespace Kandita\Paydita\Events;

/**
 * An event from Paydita, already verified. Listeners must be idempotent in business terms:
 * the package drops duplicates by event id, but check the order's own state before acting.
 */
class PaymentEvent
{
    /**
     * @param  array<string, mixed>  $data  The "data" member of the event
     */
    final public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $createdAt,
        public readonly array $data,
    ) {}

    public function externalOrderId(): string
    {
        return (string) $this->data['external_order_id'];
    }

    public function orderUuid(): string
    {
        return (string) $this->data['order_uuid'];
    }

    public function transactionUuid(): string
    {
        return (string) $this->data['transaction_uuid'];
    }

    public function grossAmount(): int
    {
        return (int) $this->data['gross_amount'];
    }

    public function environment(): string
    {
        return (string) $this->data['environment'];
    }
}
