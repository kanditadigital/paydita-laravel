<?php

namespace Kandita\Paydita\Webhook;

use Kandita\Paydita\Exceptions\InvalidWebhookException;

final class WebhookVerifier
{
    public function __construct(private readonly string $signingSecret, private readonly int $toleranceSeconds = 300) {}

    /**
     * Verify an event from Paydita and return it. Pass the raw request body, exactly as received.
     *
     * @param  string|null  $signature  X-Paydita-Signature: "v1=<hex>", or two comma-separated while the secret rotates
     * @param  string|null  $timestamp  X-Paydita-Timestamp
     * @return array<string, mixed>
     *
     * @throws InvalidWebhookException
     */
    public function verify(string $body, ?string $signature, ?string $timestamp, ?int $now = null): array
    {
        if ($this->signingSecret === '') {
            throw new InvalidWebhookException('The signing secret is not configured.');
        }

        if ($signature === null || $timestamp === null || ! ctype_digit($timestamp)) {
            throw new InvalidWebhookException('The signature headers are missing.');
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $this->toleranceSeconds) {
            throw new InvalidWebhookException('The event timestamp is outside the allowed tolerance.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, $this->signingSecret);

        $valid = false;

        foreach (explode(',', $signature) as $part) {
            $part = trim($part);

            if (str_starts_with($part, 'v1=') && hash_equals($expected, substr($part, 3))) {
                $valid = true;
            }
        }

        if (! $valid) {
            throw new InvalidWebhookException('The event signature does not match.');
        }

        $event = json_decode($body, true);

        if (! is_array($event) || ! isset($event['id'], $event['type'], $event['data']) || ! is_array($event['data'])) {
            throw new InvalidWebhookException('The event body is malformed.');
        }

        return $event;
    }
}
