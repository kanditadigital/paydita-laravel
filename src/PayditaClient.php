<?php

namespace Kandita\Paydita;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Kandita\Paydita\Data\Checkout;
use Kandita\Paydita\Data\Order;
use Kandita\Paydita\Exceptions\PayditaException;
use Kandita\Paydita\Support\Signer;

class PayditaClient
{
    private const ApiPrefix = '/api/v1';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly Signer $signer,
        private readonly array $config,
    ) {}

    /**
     * Check the connection and the credentials.
     *
     * @return array{application_uuid: string, application_code: string, environment: string}
     */
    public function ping(): array
    {
        return $this->send('GET', '/ping');
    }

    /**
     * Create an order and its first checkout. You own the products and prices: send them as items.
     * The total is computed by Paydita from the items.
     *
     * Retrying after a timeout or a 5xx is safe: the default Idempotency-Key is derived from
     * $externalOrderId, so the same call can never create a second order.
     *
     * @param  list<array{id: string, name: string, price: int, quantity: int}>  $items
     * @param  array{name: string, email: string}|null  $customer
     */
    public function createOrder(
        string $externalOrderId,
        array $items,
        ?array $customer = null,
        ?string $returnUrl = null,
        ?string $description = null,
        ?string $idempotencyKey = null,
    ): Order {
        $body = array_filter([
            'external_order_id' => $externalOrderId,
            'description' => $description,
            'items' => array_values($items),
            'customer' => $customer,
            'return_url' => $returnUrl,
        ], fn (mixed $value): bool => $value !== null);

        return Order::fromArray($this->send(
            'POST',
            '/orders',
            $body,
            $idempotencyKey ?? 'create-order-'.substr(hash('sha256', $externalOrderId), 0, 40),
        ));
    }

    /**
     * Open a new checkout for an order whose earlier attempts all ended unpaid.
     */
    public function createCheckout(string $orderUuid, ?string $idempotencyKey = null): Order
    {
        return Order::fromArray($this->send(
            'POST',
            "/orders/{$orderUuid}/checkouts",
            null,
            $idempotencyKey ?? 'create-checkout-'.bin2hex(random_bytes(16)),
        ));
    }

    public function getOrder(string $orderUuid): Order
    {
        return Order::fromArray($this->send('GET', "/orders/{$orderUuid}"));
    }

    public function getTransaction(string $transactionUuid): Checkout
    {
        return Checkout::fromArray($this->send('GET', "/transactions/{$transactionUuid}"));
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed> The "data" member of the response.
     */
    private function send(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $this->required('callback_url');

        $json = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $path = self::ApiPrefix.$path;

        $headers = $this->signer->headers($this->required('key'), $this->required('secret'), $method, $path, $json);

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $request = $this->pending($headers);

        if ($json !== '') {
            $request = $request->withBody($json, 'application/json');
        }

        try {
            $response = $request->send($method, rtrim($this->required('base_url'), '/').$path);
        } catch (ConnectionException $exception) {
            throw PayditaException::unreachable($exception);
        }

        return $this->data($response);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function pending(array $headers): PendingRequest
    {
        return $this->http
            ->withHeaders($headers + ['Accept' => 'application/json'])
            ->timeout((int) ($this->config['timeout'] ?? 15))
            ->withoutRedirecting();
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Response $response): array
    {
        if (! $response->successful()) {
            throw PayditaException::fromResponse($response);
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            throw new PayditaException('Paydita answered with an unexpected body.', $response->status());
        }

        return $data;
    }

    private function required(string $setting): string
    {
        $value = $this->config[$setting] ?? null;

        if (! is_string($value) || $value === '') {
            throw PayditaException::notConfigured($setting);
        }

        return $value;
    }
}
