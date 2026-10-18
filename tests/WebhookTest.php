<?php

use Illuminate\Support\Facades\Event;
use Kandita\Paydita\Events\PaymentEvent;
use Kandita\Paydita\Events\PaymentExpired;
use Kandita\Paydita\Events\PaymentPaid;
use Kandita\Paydita\Exceptions\InvalidWebhookException;
use Kandita\Paydita\Webhook\WebhookVerifier;

const SECRET = 'whsec_sand_testsecret';

function eventBody(string $id = '01a14c3a-8000-7000-8000-000000000003', string $type = 'payment.paid'): string
{
    return json_encode([
        'id' => $id,
        'type' => $type,
        'created_at' => '2026-10-18T10:05:00+00:00',
        'data' => [
            'environment' => 'sandbox',
            'order_uuid' => '01a14c3a-7a30-7000-8000-000000000001',
            'external_order_id' => 'INV-1',
            'transaction_uuid' => '01a14c3a-7a31-7000-8000-000000000002',
            'status' => 'paid',
            'gross_amount' => 300_000,
        ],
    ]);
}

function signedHeaders(string $body, ?int $timestamp = null, string $secret = SECRET): array
{
    $timestamp ??= time();

    return [
        'X-Paydita-Timestamp' => (string) $timestamp,
        'X-Paydita-Signature' => 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
    ];
}

function postEvent($test, string $body, array $headers)
{
    $server = collect($headers)->mapWithKeys(fn ($value, $name) => ['HTTP_'.strtoupper(str_replace('-', '_', $name)) => $value])->all();

    return $test->call('POST', '/paydita/events', [], [], [], $server + ['CONTENT_TYPE' => 'application/json'], $body);
}

describe('the verifier', function () {
    it('returns the event when the signature is right', function () {
        $body = eventBody();
        $headers = signedHeaders($body);

        $event = (new WebhookVerifier(SECRET))->verify($body, $headers['X-Paydita-Signature'], $headers['X-Paydita-Timestamp']);

        expect($event['type'])->toBe('payment.paid');
    });

    it('accepts either signature while the secret is being rotated', function () {
        $body = eventBody();
        $old = signedHeaders($body, secret: 'whsec_sand_old');
        $new = signedHeaders($body);

        $event = (new WebhookVerifier(SECRET))->verify($body, $old['X-Paydita-Signature'].','.$new['X-Paydita-Signature'], $new['X-Paydita-Timestamp']);

        expect($event['id'])->not->toBeEmpty();
    });

    it('rejects a wrong secret, a changed body, a stale timestamp and missing headers', function (callable $make) {
        [$body, $signature, $timestamp] = $make();

        expect(fn () => (new WebhookVerifier(SECRET))->verify($body, $signature, $timestamp))->toThrow(InvalidWebhookException::class);
    })->with([
        'wrong secret' => [fn () => [eventBody(), signedHeaders(eventBody(), secret: 'whsec_other')['X-Paydita-Signature'], (string) time()]],
        'changed body' => [function () {
            $headers = signedHeaders(eventBody());

            return [str_replace('300000', '1', eventBody()), $headers['X-Paydita-Signature'], $headers['X-Paydita-Timestamp']];
        }],
        'stale timestamp' => [function () {
            $old = time() - 3600;

            return [eventBody(), signedHeaders(eventBody(), $old)['X-Paydita-Signature'], (string) $old];
        }],
        'no signature' => [fn () => [eventBody(), null, (string) time()]],
        'no timestamp' => [fn () => [eventBody(), 'v1=abc', null]],
        'unsigned scheme' => [fn () => [eventBody(), 'v2=abc', (string) time()]],
    ]);

    it('refuses everything when no signing secret is configured', function () {
        $body = eventBody();
        $headers = signedHeaders($body, secret: '');

        expect(fn () => (new WebhookVerifier(''))->verify($body, $headers['X-Paydita-Signature'], $headers['X-Paydita-Timestamp']))->toThrow(InvalidWebhookException::class);
    });
});

describe('the route', function () {
    it('dispatches a typed event once and acknowledges with no content', function () {
        Event::fake([PaymentPaid::class]);
        $body = eventBody();

        postEvent($this, $body, signedHeaders($body))->assertNoContent();

        Event::assertDispatched(PaymentPaid::class, fn (PaymentPaid $event) => $event->externalOrderId() === 'INV-1' && $event->grossAmount() === 300_000 && $event->environment() === 'sandbox');
    });

    it('dispatches the matching class for each event type', function () {
        Event::fake([PaymentExpired::class]);
        $body = eventBody(type: 'payment.expired');

        postEvent($this, $body, signedHeaders($body))->assertNoContent();

        Event::assertDispatched(PaymentExpired::class);
    });

    it('falls back to the base event for a type it does not know yet', function () {
        Event::fake([PaymentEvent::class]);
        $body = eventBody(type: 'payment.something_new');

        postEvent($this, $body, signedHeaders($body))->assertNoContent();

        Event::assertDispatched(PaymentEvent::class, fn (PaymentEvent $event) => $event->type === 'payment.something_new');
    });

    it('acknowledges a repeated event without handling it twice', function () {
        Event::fake([PaymentPaid::class]);
        $body = eventBody();

        postEvent($this, $body, signedHeaders($body))->assertNoContent();
        postEvent($this, $body, signedHeaders($body))->assertNoContent();

        Event::assertDispatchedTimes(PaymentPaid::class, 1);
    });

    it('answers 400 and dispatches nothing for a bad signature', function () {
        Event::fake([PaymentEvent::class, PaymentPaid::class]);
        $body = eventBody();

        postEvent($this, $body, signedHeaders($body, secret: 'whsec_other'))->assertStatus(400);

        Event::assertNotDispatched(PaymentPaid::class);
        Event::assertNotDispatched(PaymentEvent::class);
    });

    it('lets a failing listener fail the request, so Paydita retries and the retry is handled', function () {
        $calls = 0;
        Event::listen(PaymentPaid::class, function () use (&$calls) {
            if (++$calls === 1) {
                throw new RuntimeException('database down');
            }
        });
        $body = eventBody();

        $this->withoutExceptionHandling();
        expect(fn () => postEvent($this, $body, signedHeaders($body)))->toThrow(RuntimeException::class);

        postEvent($this, $body, signedHeaders($body))->assertNoContent();

        expect($calls)->toBe(2);
    });
});
