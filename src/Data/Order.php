<?php

namespace Kandita\Paydita\Data;

use Illuminate\Http\RedirectResponse;
use Kandita\Paydita\Exceptions\PayditaException;

final readonly class Order
{
    /**
     * @param  list<array{id: string, name: string, price: int, quantity: int}>  $items
     * @param  array{name: string, email: string}|null  $customer
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $uuid,
        public string $externalOrderId,
        public string $status,
        public string $currency,
        public int $grossAmount,
        public array $items,
        public ?Checkout $checkout,
        public ?string $description = null,
        public ?array $customer = null,
        public ?string $returnUrl = null,
        public ?string $createdAt = null,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uuid: (string) $data['uuid'],
            externalOrderId: (string) $data['external_order_id'],
            status: (string) $data['status'],
            currency: (string) $data['currency'],
            grossAmount: (int) $data['gross_amount'],
            items: array_values($data['items'] ?? []),
            checkout: is_array($data['checkout'] ?? null) ? Checkout::fromArray($data['checkout']) : null,
            description: $data['description'] ?? null,
            customer: is_array($data['customer'] ?? null) ? $data['customer'] : null,
            returnUrl: $data['return_url'] ?? null,
            createdAt: $data['created_at'] ?? null,
            raw: $data,
        );
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function checkoutUrl(): ?string
    {
        return $this->checkout?->checkoutUrl;
    }

    /**
     * Send the buyer's browser to the payment page.
     */
    public function redirectToCheckout(): RedirectResponse
    {
        if ($this->checkout === null) {
            throw new PayditaException('This order has no checkout yet. Call Paydita::createCheckout($order->uuid).');
        }

        return $this->checkout->redirect();
    }
}
