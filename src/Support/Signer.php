<?php

namespace Kandita\Paydita\Support;

/**
 * Signs requests to Paydita: HMAC-SHA256 over
 * METHOD, REQUEST_URI, TIMESTAMP, NONCE and the SHA-256 of the exact body, one per line.
 */
final class Signer
{
    /**
     * @return array<string, string>
     */
    public function headers(string $key, string $secret, string $method, string $path, string $body, ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp ??= time();
        $nonce ??= bin2hex(random_bytes(16));

        $canonical = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $body)]);

        return [
            'X-Paydita-Key' => $key,
            'X-Paydita-Timestamp' => (string) $timestamp,
            'X-Paydita-Nonce' => $nonce,
            'X-Paydita-Signature' => hash_hmac('sha256', $canonical, $secret),
        ];
    }
}
