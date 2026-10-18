<?php

namespace Kandita\Paydita\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * A request to Paydita failed. Log errorCode() and requestId(), never the credentials.
 */
class PayditaException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        string $message,
        private readonly ?int $status = null,
        private readonly ?string $errorCode = null,
        private readonly ?string $requestId = null,
        private readonly array $details = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];

        return new self(
            sprintf(
                'Paydita answered %d %s%s.',
                $response->status(),
                $error['code'] ?? 'UNKNOWN_ERROR',
                isset($error['request_id']) ? " (request {$error['request_id']})" : '',
            ),
            $response->status(),
            $error['code'] ?? null,
            $error['request_id'] ?? $response->header('X-Request-Id') ?: null,
            is_array($error['details'] ?? null) ? $error['details'] : [],
        );
    }

    public static function unreachable(Throwable $previous): self
    {
        return new self('Paydita could not be reached: '.$previous->getMessage(), previous: $previous);
    }

    public static function notConfigured(string $setting): self
    {
        return new self("Paydita is not configured: set paydita.{$setting} (PAYDITA_".strtoupper($setting).').');
    }

    public function status(): ?int
    {
        return $this->status;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /**
     * @return array<string, mixed> For a validation error, the messages are under "fields".
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * Whether repeating the same call (with the same Idempotency-Key) could succeed.
     */
    public function isRetryable(): bool
    {
        return $this->status === null || $this->status >= 500 || $this->status === 429;
    }
}
