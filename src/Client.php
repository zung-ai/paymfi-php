<?php

declare(strict_types=1);

namespace PayMfi;

use PayMfi\Exception\ApiException;
use PayMfi\Exception\InvalidArgumentException;
use PayMfi\Exception\PayMfiExceptionInterface;
use PayMfi\Http\CurlHttpClient;
use PayMfi\Http\HttpClientInterface;

/**
 * Client for the PayMfi API, scoped to ONE merchant account.
 *
 * Multi-tenant apps (each business with its own PayMfi merchant account)
 * should build one Client per merchant from that merchant's own
 * credentials; nothing here is global or static.
 */
final class Client
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://paymfi.com';
    public const ENV_SANDBOX = 'sandbox';
    public const ENV_PRODUCTION = 'production';

    private const INITIATE_PARAMS = [
        'amount', 'currency', 'success_url', 'ipn_url',
        'reference', 'description', 'cancel_url', 'customer_name', 'customer_email',
    ];

    private readonly string $baseUrl;
    private readonly HttpClientInterface $http;

    /**
     * @throws InvalidArgumentException When a credential is empty, the environment is unknown, or the base URL is invalid.
     */
    public function __construct(
        private readonly string $merchantKey,
        private readonly string $apiKey,
        private readonly string $clientSecret,
        private readonly string $environment = self::ENV_SANDBOX,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?HttpClientInterface $http = null,
    ) {
        foreach (['merchantKey' => $merchantKey, 'apiKey' => $apiKey, 'clientSecret' => $clientSecret] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(sprintf('"%s" must not be empty.', $name));
            }
        }

        if (!in_array($environment, [self::ENV_SANDBOX, self::ENV_PRODUCTION], true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown environment "%s"; expected "%s" or "%s".',
                $environment,
                self::ENV_SANDBOX,
                self::ENV_PRODUCTION
            ));
        }

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid base URL.', $baseUrl));
        }

        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = $http ?? new CurlHttpClient();
    }

    /**
     * Starts a hosted-checkout payment.
     *
     * Parameters:
     *  - amount         (required) number > 0
     *  - currency       (required) currency code, e.g. "KES"
     *  - success_url    (required) where PayMfi sends the customer after paying
     *  - ipn_url        (required) your webhook endpoint for server-to-server notification
     *  - reference      (optional) your own unique reference; generated if omitted
     *  - description    (optional)
     *  - cancel_url     (optional) defaults to success_url
     *  - customer_name  (optional)
     *  - customer_email (optional)
     *
     * @param array<string, mixed> $params
     *
     * @throws InvalidArgumentException On invalid parameters (no request is made).
     * @throws ApiException             When PayMfi rejects the request or returns no payment URL.
     * @throws \PayMfi\Exception\ConnectionException When PayMfi cannot be reached.
     */
    public function initiatePayment(array $params): PaymentSession
    {
        $unknown = array_diff(array_keys($params), self::INITIATE_PARAMS);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown parameter(s): %s. Allowed: %s.',
                implode(', ', $unknown),
                implode(', ', self::INITIATE_PARAMS)
            ));
        }

        $amount = $params['amount'] ?? null;
        if (!is_int($amount) && !is_float($amount) && !(is_string($amount) && is_numeric($amount))) {
            throw new InvalidArgumentException('"amount" is required and must be numeric.');
        }
        if ((float) $amount <= 0) {
            throw new InvalidArgumentException('"amount" must be greater than zero.');
        }

        $currency = $this->requireString($params, 'currency');
        $successUrl = $this->requireString($params, 'success_url');
        $ipnUrl = $this->requireString($params, 'ipn_url');

        $reference = $this->optionalString($params, 'reference') ?? self::generateReference();
        $cancelUrl = $this->optionalString($params, 'cancel_url') ?? $successUrl;

        $body = [
            'payment_amount' => $amount,
            'currency_code' => strtoupper($currency),
            'ref_trx' => $reference,
            'description' => $this->optionalString($params, 'description'),
            'success_redirect' => $successUrl,
            'cancel_redirect' => $cancelUrl,
            'ipn_url' => $ipnUrl,
            'customer_name' => $this->optionalString($params, 'customer_name'),
            'customer_email' => $this->optionalString($params, 'customer_email'),
        ];

        $response = $this->send('POST', '/api/v1/initiate-payment', $body);

        $paymentUrl = $response['payment_url'] ?? null;
        if (!is_string($paymentUrl) || $paymentUrl === '') {
            $message = is_string($response['message'] ?? null) ? $response['message'] : 'PayMfi did not return a payment_url.';

            throw new ApiException($message, 200, $response);
        }

        return new PaymentSession($paymentUrl, $reference, $response);
    }

    /**
     * Asks PayMfi for the authoritative state of a transaction. Always call
     * this (or rely on the signed webhook) before fulfilling an order: the
     * browser redirect back to your site can be forged by the customer.
     *
     * Returns the decoded JSON exactly as the API returned it.
     *
     * @return array<mixed>
     *
     * @throws InvalidArgumentException
     * @throws ApiException
     * @throws \PayMfi\Exception\ConnectionException
     */
    public function verifyPayment(string $transactionId): array
    {
        if (trim($transactionId) === '') {
            throw new InvalidArgumentException('"transactionId" must not be empty.');
        }

        return $this->send('GET', '/api/v1/verify-payment/' . rawurlencode($transactionId));
    }

    /**
     * Authenticated, side-effect-free call returning the merchant's site info.
     *
     * @return array<mixed>
     *
     * @throws ApiException
     * @throws \PayMfi\Exception\ConnectionException
     */
    public function siteInfo(): array
    {
        return $this->send('GET', '/api/v1/site-info');
    }

    /**
     * Never throws for API/network problems: reports them in the result, so
     * it can back a "Test connection" button directly.
     */
    public function testConnection(): ConnectionResult
    {
        try {
            $data = $this->siteInfo();
        } catch (PayMfiExceptionInterface $e) {
            return new ConnectionResult(false, $e->getMessage());
        }

        if (($data['status'] ?? null) !== 'active') {
            $message = is_string($data['message'] ?? null)
                ? $data['message']
                : 'Could not connect. Check your credentials and environment.';

            return new ConnectionResult(false, $message, $data);
        }

        $site = is_string($data['site_name'] ?? null) ? $data['site_name'] : 'PayMfi';

        return new ConnectionResult(true, sprintf('Connected successfully to %s.', $site), $data);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     */
    private function send(string $method, string $path, ?array $body = null): array
    {
        $timestamp = (string) time();

        try {
            $rawBody = $method === 'GET'
                ? ''
                : json_encode($body ?? [], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Request could not be encoded as JSON: ' . $e->getMessage(), 0, $e);
        }

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'paymfi-php/' . self::VERSION,
            'X-Environment' => $this->environment,
            'X-Merchant-Key' => $this->merchantKey,
            'X-API-Key' => $this->apiKey,
            'X-Timestamp' => $timestamp,
            'X-Signature' => 'sha256=' . Signer::sign($this->clientSecret, $timestamp, $method, $path, $rawBody),
        ];

        if ($method !== 'GET') {
            $headers['Content-Type'] = 'application/json';
        }

        $response = $this->http->request($method, $this->baseUrl . $path, $headers, $method === 'GET' ? null : $rawBody);

        $decoded = null;
        if ($response->body !== '') {
            $decoded = json_decode($response->body, true);
        }

        if ($response->status < 200 || $response->status >= 300) {
            $message = is_array($decoded) && is_string($decoded['message'] ?? null)
                ? $decoded['message']
                : sprintf('PayMfi API returned HTTP %d.', $response->status);

            throw new ApiException($message, $response->status, is_array($decoded) ? $decoded : []);
        }

        if ($response->body !== '' && !is_array($decoded)) {
            throw new ApiException('PayMfi returned a response that is not valid JSON.', $response->status);
        }

        return $decoded ?? [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function requireString(array $params, string $key): string
    {
        $value = $params[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('"%s" is required and must be a non-empty string.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function optionalString(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('"%s" must be a string.', $key));
        }

        return $value;
    }

    private static function generateReference(): string
    {
        return 'PMF' . strtoupper(bin2hex(random_bytes(8)));
    }
}
