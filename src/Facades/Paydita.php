<?php

namespace Kandita\Paydita\Facades;

use Illuminate\Support\Facades\Facade;
use Kandita\Paydita\Data\Checkout;
use Kandita\Paydita\Data\Order;
use Kandita\Paydita\PayditaClient;
use Kandita\Paydita\Testing\FakePaydita;

/**
 * @method static array ping()
 * @method static Order createOrder(string $externalOrderId, array $items, ?array $customer = null, ?string $returnUrl = null, ?string $description = null, ?string $idempotencyKey = null)
 * @method static Order createCheckout(string $orderUuid, ?string $idempotencyKey = null)
 * @method static Order getOrder(string $orderUuid)
 * @method static Checkout getTransaction(string $transactionUuid)
 *
 * @see PayditaClient
 */
class Paydita extends Facade
{
    /**
     * Replace the client with a recording fake that never calls Paydita.
     */
    public static function fake(): FakePaydita
    {
        $fake = new FakePaydita;

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return PayditaClient::class;
    }
}
