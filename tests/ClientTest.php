<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kandita\Paydita\Exceptions\PayditaException;
use Kandita\Paydita\Facades\Paydita;

function orderResponse(array $overrides = []): array
{
    return ['data' => [
        'uuid' => '01a14c3a-7a30-7000-8000-000000000001',
        'external_order_id' => 'INV-1',
        'status' => 'pending',
        'currency' => 'IDR',
        'gross_amount' => 300_000,
        'items' => [['id' => 'a', 'name' => 'Plan', 'price' => 150_000, 'quantity' => 2]],
        'checkout' => [
            'uuid' => '01a14c3a-7a31-7000-8000-000000000002',
            'payment_reference' => 'PAY-20261018-ABCDEF123456',
            'status' => 'pending',
            'checkout_url' => 'https://app.sandbox.midtrans.com/snap/v4/redirection/token',
            'expires_at' => '2026-10-18T15:00:00+07:00',
        ],
        ...$overrides,
    ]];
}

$items = [['id' => 'a', 'name' => 'Plan', 'price' => 150_000, 'quantity' => 2]];

it('creates an order and exposes the checkout url', function () use ($items) {
    Http::fake(['*' => Http::response(orderResponse(), 201)]);

    $order = Paydita::createOrder('INV-1', $items, ['name' => 'Budi', 'email' => 'b@example.com'], 'https://app.example.test/done');

    expect($order->uuid)->toBe('01a14c3a-7a30-7000-8000-000000000001')
        ->and($order->grossAmount)->toBe(300_000)
        ->and($order->checkoutUrl())->toBe('https://app.sandbox.midtrans.com/snap/v4/redirection/token');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://pay.example.test/api/v1/orders'
        && $request->method() === 'POST'
        && $request['external_order_id'] === 'INV-1'
        && $request['items'] === $items
        && $request['customer']['email'] === 'b@example.com');
});

it('signs exactly the method, path, timestamp, nonce and body it sends', function () use ($items) {
    Http::fake(['*' => Http::response(orderResponse(), 201)]);

    Paydita::createOrder('INV-1', $items);

    Http::assertSent(function (Request $request) {
        $canonical = implode("\n", [
            'POST',
            '/api/v1/orders',
            $request->header('X-Paydita-Timestamp')[0],
            $request->header('X-Paydita-Nonce')[0],
            hash('sha256', $request->body()),
        ]);

        return $request->header('X-Paydita-Key')[0] === 'pk_sand_testkey'
            && $request->header('X-Paydita-Signature')[0] === hash_hmac('sha256', $canonical, 'ps_sand_testsecret')
            && preg_match('/^[a-f0-9]{64}$/', $request->header('X-Paydita-Signature')[0]) === 1
            && ! str_contains(json_encode($request->headers()), 'ps_sand_testsecret');
    });
});

it('signs the hash of an empty body for a read', function () {
    Http::fake(['*' => Http::response(orderResponse(), 200)]);

    Paydita::getOrder('01a14c3a-7a30-7000-8000-000000000001');

    Http::assertSent(function (Request $request) {
        $canonical = implode("\n", ['GET', '/api/v1/orders/01a14c3a-7a30-7000-8000-000000000001', $request->header('X-Paydita-Timestamp')[0], $request->header('X-Paydita-Nonce')[0], hash('sha256', '')]);

        return $request->header('X-Paydita-Signature')[0] === hash_hmac('sha256', $canonical, 'ps_sand_testsecret');
    });
});

it('uses a stable idempotency key per external order id, so a retry cannot create a second order', function () use ($items) {
    Http::fake(['*' => Http::response(orderResponse(), 201)]);

    Paydita::createOrder('INV-1', $items);
    Paydita::createOrder('INV-1', $items);
    Paydita::createOrder('INV-2', $items);

    $keys = collect(Http::recorded())->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0]);

    expect($keys[0])->toBe($keys[1])->not->toBe($keys[2])
        ->and($keys[0])->toMatch('/^[A-Za-z0-9._:-]{16,255}$/');
});

it('lets the caller choose the idempotency key', function () use ($items) {
    Http::fake(['*' => Http::response(orderResponse(), 201)]);

    Paydita::createOrder('INV-1', $items, idempotencyKey: 'my-own-key-0000001');

    Http::assertSent(fn (Request $request) => $request->header('Idempotency-Key')[0] === 'my-own-key-0000001');
});

it('turns an error response into an exception with the code and request id', function () use ($items) {
    Http::fake(['*' => Http::response(['error' => [
        'code' => 'VALIDATION_FAILED',
        'message' => 'The request is invalid.',
        'request_id' => 'req_abc',
        'details' => ['fields' => ['items.0.price' => ['The items.0.price field must be at least 1.']]],
    ]], 422)]);

    try {
        Paydita::createOrder('INV-1', $items);
    } catch (PayditaException $exception) {
        expect($exception->status())->toBe(422)
            ->and($exception->errorCode())->toBe('VALIDATION_FAILED')
            ->and($exception->requestId())->toBe('req_abc')
            ->and($exception->details()['fields'])->toHaveKey('items.0.price')
            ->and($exception->isRetryable())->toBeFalse()
            ->and($exception->getMessage())->not->toContain('ps_sand_testsecret');

        return;
    }

    $this->fail('No exception was thrown.');
});

it('marks server errors and rate limits as retryable', function (int $status, bool $retryable) {
    Http::fake(['*' => Http::response(['error' => ['code' => 'X', 'message' => 'm', 'request_id' => 'r']], $status)]);

    try {
        Paydita::getOrder('x');
    } catch (PayditaException $exception) {
        expect($exception->isRetryable())->toBe($retryable);
    }
})->with([[500, true], [502, true], [429, true], [401, false], [404, false], [409, false]]);

it('wraps a connection failure', function () {
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('timeout'));

    expect(fn () => Paydita::ping())->toThrow(PayditaException::class, 'could not be reached');
});

it('refuses to send anything when it is not configured', function () {
    config()->set('paydita.secret', null);
    app()->forgetInstance(Kandita\Paydita\PayditaClient::class);
    Http::fake();

    expect(fn () => Paydita::ping())->toThrow(PayditaException::class, 'not configured');

    Http::assertNothingSent();
});

it('redirects the buyer to the payment page', function () use ($items) {
    Http::fake(['*' => Http::response(orderResponse(), 201)]);

    expect(Paydita::createOrder('INV-1', $items)->redirectToCheckout()->getTargetUrl())
        ->toBe('https://app.sandbox.midtrans.com/snap/v4/redirection/token');
});

it('refuses to redirect when there is no open payment page', function () use ($items) {
    Http::fake(['*' => Http::response(orderResponse(['checkout' => null]), 201)]);

    expect(fn () => Paydita::createOrder('INV-2', $items)->redirectToCheckout())->toThrow(PayditaException::class);
});

it('pings', function () {
    Http::fake(['*' => Http::response(['data' => ['application_uuid' => 'u', 'application_code' => 'lapaku', 'environment' => 'sandbox']])]);

    expect(Paydita::ping()['application_code'])->toBe('lapaku');

    $this->artisan('paydita:ping')->expectsOutputToContain('Connected as lapaku in sandbox')->assertSuccessful();
});

it('can be faked in the tests of an application', function () use ($items) {
    $fake = Paydita::fake();

    $order = Paydita::createOrder('INV-9', $items);

    expect($order->grossAmount)->toBe(300_000)->and($order->checkoutUrl())->toStartWith('https://paydita.test/checkout/');

    $fake->assertOrderCreated(fn (array $call) => $call['externalOrderId'] === 'INV-9');
});
