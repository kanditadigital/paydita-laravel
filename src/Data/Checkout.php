<?php

namespace Kandita\Paydita\Data;

use Illuminate\Http\RedirectResponse;
use Kandita\Paydita\Exceptions\PayditaException;

/**
 * One payment attempt. checkoutUrl is only present while the attempt is still open.
 */
final readonly class Checkout
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $uuid,
        public string $paymentReference,
        public string $status,
        public ?string $checkoutUrl,
        public ?string $expiresAt,
        public ?string $paymentType,
        public ?string $paidAt,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uuid: (string) $data['uuid'],
            paymentReference: (string) $data['payment_reference'],
            status: (string) $data['status'],
            checkoutUrl: $data['checkout_url'] ?? null,
            expiresAt: $data['expires_at'] ?? null,
            paymentType: $data['payment_type'] ?? null,
            paidAt: $data['paid_at'] ?? null,
            raw: $data,
        );
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Send the buyer's browser to the payment page. There is no popup: the page is hosted by Midtrans.
     */
    public function redirect(): RedirectResponse
    {
        if ($this->checkoutUrl === null) {
            throw new PayditaException('This payment attempt has no open payment page (status: '.$this->status.'). Create a new checkout.');
        }

        return redirect()->away($this->checkoutUrl);
    }
}
