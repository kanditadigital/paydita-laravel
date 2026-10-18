<?php

namespace Kandita\Paydita\Http;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Kandita\Paydita\Events\PaymentCancelled;
use Kandita\Paydita\Events\PaymentEvent;
use Kandita\Paydita\Events\PaymentExpired;
use Kandita\Paydita\Events\PaymentFailed;
use Kandita\Paydita\Events\PaymentPaid;
use Kandita\Paydita\Events\PaymentPartiallyRefunded;
use Kandita\Paydita\Events\PaymentRefunded;
use Kandita\Paydita\Exceptions\InvalidWebhookException;
use Kandita\Paydita\Webhook\WebhookVerifier;
use Throwable;

final class ReceiveEventController
{
    private const Events = [
        'payment.paid' => PaymentPaid::class,
        'payment.failed' => PaymentFailed::class,
        'payment.cancelled' => PaymentCancelled::class,
        'payment.expired' => PaymentExpired::class,
        'payment.refunded' => PaymentRefunded::class,
        'payment.partially_refunded' => PaymentPartiallyRefunded::class,
    ];

    public function __construct(private readonly WebhookVerifier $verifier, private readonly Dispatcher $events, private readonly Cache $cache) {}

    public function __invoke(Request $request): Response
    {
        try {
            $event = $this->verifier->verify(
                $request->getContent(),
                $request->header('X-Paydita-Signature'),
                $request->header('X-Paydita-Timestamp'),
            );
        } catch (InvalidWebhookException) {
            return response('Invalid event.', 400);
        }

        $key = 'paydita:event:'.$event['id'];

        // The first delivery wins; a retry of an event already handled is acknowledged and ignored.
        if (! $this->cache->add($key, true, (int) config('paydita.webhook.remember_event_ids_for_seconds'))) {
            return response()->noContent();
        }

        $class = self::Events[$event['type']] ?? PaymentEvent::class;
        $payment = new $class((string) $event['id'], (string) $event['type'], (string) ($event['created_at'] ?? ''), $event['data']);

        try {
            $this->events->dispatch($payment);
        } catch (Throwable $exception) {
            // Forget the id so Paydita's retry can be handled once the listener works again.
            $this->cache->forget($key);

            throw $exception;
        }

        return response()->noContent();
    }
}
