<?php

namespace Kandita\Paydita\Testing;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Str;
use Kandita\Paydita\Data\Checkout;
use Kandita\Paydita\Data\Order;
use Kandita\Paydita\PayditaClient;
use Kandita\Paydita\Support\Signer;
use PHPUnit\Framework\Assert;

/**
 * A stand-in for the client in your tests: nothing leaves the process.
 */
class FakePaydita extends PayditaClient
{
    /** @var list<array<string, mixed>> */
    private array $created = [];

    public function __construct()
    {
        parent::__construct(new Factory, new Signer, []);
    }

    public function ping(): array
    {
        return ['application_uuid' => (string) Str::uuid(), 'application_code' => 'fake', 'environment' => 'sandbox'];
    }

    public function createOrder(string $externalOrderId, array $items, ?array $customer = null, ?string $returnUrl = null, ?string $description = null, ?string $idempotencyKey = null): Order
    {
        $this->created[] = compact('externalOrderId', 'items', 'customer', 'returnUrl', 'description');

        $orderUuid = (string) Str::uuid();
        $total = array_sum(array_map(fn (array $item): int => $item['price'] * $item['quantity'], $items));

        return new Order(
            uuid: $orderUuid,
            externalOrderId: $externalOrderId,
            status: 'pending',
            currency: 'IDR',
            grossAmount: $total,
            items: array_values($items),
            checkout: new Checkout(
                uuid: (string) Str::uuid(),
                paymentReference: 'PAY-FAKE-'.strtoupper(Str::random(12)),
                status: 'pending',
                checkoutUrl: 'https://paydita.test/checkout/'.$orderUuid,
                expiresAt: null,
                paymentType: null,
                paidAt: null,
            ),
        );
    }

    /**
     * @param  (callable(array<string, mixed>): bool)|null  $callback  Receives externalOrderId, items, customer, returnUrl, description
     */
    public function assertOrderCreated(?callable $callback = null): void
    {
        $matching = $callback === null ? $this->created : array_filter($this->created, $callback);

        Assert::assertNotEmpty($matching, 'No matching Paydita order was created.');
    }

    public function assertNothingCreated(): void
    {
        Assert::assertEmpty($this->created, 'Paydita orders were created unexpectedly.');
    }
}
