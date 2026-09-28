# paymfi-php

PHP client for the [PayMfi](https://paymfi.com) eWallet & Payments API.

- Signed requests (HMAC-SHA256) handled for you
- Hosted checkout: `initiatePayment()` returns the URL to send your customer to
- Server-side verification: `verifyPayment()`
- Webhook (IPN) signature verification that is constant-time and raw-body correct
- Zero runtime dependencies beyond `ext-curl` and `ext-json`; framework-agnostic
- Pluggable HTTP transport, so it is trivial to fake in tests

Extracted from the PayMfi integration in the Zung.ai platform. Status: **0.x**. The API surface may still change before 1.0.

## Requirements

PHP 8.1+, `ext-curl`, `ext-json`.

## Installation

```bash
composer require paymfi/paymfi-php
```

## Quick start

Each `Client` is scoped to **one merchant account** (merchant key, API key, client secret). Build one per merchant; nothing is global.

```php
use PayMfi\Client;

$paymfi = new Client(
    merchantKey: getenv('PAYMFI_MERCHANT_KEY'),
    apiKey:      getenv('PAYMFI_API_KEY'),
    clientSecret: getenv('PAYMFI_CLIENT_SECRET'),
    environment: Client::ENV_SANDBOX,      // or Client::ENV_PRODUCTION
);

$session = $paymfi->initiatePayment([
    'amount'         => 1500.00,
    'currency'       => 'KES',
    'success_url'    => 'https://shop.example/payments/return',
    'ipn_url'        => 'https://shop.example/webhooks/paymfi',
    'reference'      => 'ORDER-1001',          // optional; generated if omitted
    'description'    => 'Wallet top-up',       // optional
    'customer_name'  => 'Jane Doe',            // optional
    'customer_email' => 'jane@example.com',    // optional
    // 'cancel_url'  => '...',                 // optional; defaults to success_url
]);

header('Location: ' . $session->paymentUrl);   // send the customer to PayMfi
```

Store `$session->reference` so you can match the payment up later.

## Confirming a payment

**Never fulfil an order because the customer's browser landed on your `success_url`.** That request can be forged. Confirm server-side, either or both of:

1. Ask PayMfi directly:

   ```php
   $result = $paymfi->verifyPayment($transactionId);   // decoded JSON, exactly as returned by the API
   ```

   (The reference integration reads `ref_trx` and `trx_id` from the return URL's query string.)

2. Handle the signed webhook (below), which is the authoritative notification.

Make fulfilment idempotent (key it on the transaction id): a valid webhook can be delivered more than once.

## Webhooks

PayMfi signs each notification with the merchant's **webhook secret** (`X-Signature: sha256=<hex>`, HMAC-SHA256 of the raw body). Each merchant account has its own secret; verify against the secret of the merchant the notification belongs to.

> Pass the **raw request body**. Decoding the JSON and re-encoding it changes the bytes and breaks the signature.

Plain PHP:

```php
use PayMfi\Webhook;
use PayMfi\Exception\PayMfiExceptionInterface;

$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? null;

try {
    $event = Webhook::constructEvent($rawBody, $signature, $webhookSecret);
} catch (PayMfiExceptionInterface $e) {
    http_response_code(400);   // bad signature or body: ignore it
    exit;
}

// ...idempotently fulfil the order using $event...
http_response_code(200);
```

Laravel (exclude the route from CSRF verification, since it is called server-to-server):

```php
Route::post('/webhooks/paymfi', function (Request $request) use ($webhookSecret) {
    abort_unless(
        \PayMfi\Webhook::verifySignature($request->getContent(), $request->header('X-Signature'), $webhookSecret),
        401
    );

    $event = $request->json()->all();
    // ...idempotently fulfil...
    return response()->noContent();
});
```

## Testing your connection

```php
$check = $paymfi->testConnection();   // never throws for API/network problems

$check->success;   // bool
$check->message;   // e.g. "Connected successfully to Acme SACCO."
```

This calls a signed, side-effect-free endpoint, so it is safe behind a "Test connection" button in a settings screen.

## Error handling

Every exception implements `PayMfi\Exception\PayMfiExceptionInterface`:

| Exception | Meaning |
|---|---|
| `InvalidArgumentException` | Bad input, caught **before** any request is made |
| `ApiException` | PayMfi answered with a non-2xx status, an unusable body, or no `payment_url`. `getStatusCode()` / `getBody()` |
| `ConnectionException` | No response at all (DNS, refused, timeout, TLS) |
| `SignatureVerificationException` | Webhook signature missing or wrong |
| `InvalidPayloadException` | Webhook signature was valid but the body is not a JSON object |

## Custom HTTP transport / testing

Implement `PayMfi\Http\HttpClientInterface` and pass it as the last constructor argument. Your implementation **must send the body byte-for-byte as given**, because the signature is computed over those exact bytes. In tests, use a fake that records the request and returns a canned `PayMfi\Http\Response`.

```php
$paymfi = new Client('mk', 'ak', 'secret', 'sandbox', Client::DEFAULT_BASE_URL, $fakeHttp);
```

## Reference

| Method | API call |
|---|---|
| `initiatePayment(array $params): PaymentSession` | `POST /api/v1/initiate-payment` |
| `verifyPayment(string $transactionId): array` | `GET /api/v1/verify-payment/{id}` |
| `siteInfo(): array` | `GET /api/v1/site-info` |
| `testConnection(): ConnectionResult` | `GET /api/v1/site-info`, reported as a result object |
| `Webhook::verifySignature(...)` / `Webhook::constructEvent(...)` | (local) |

Parameter names and response fields follow the PayMfi API as used by the reference integration; response bodies are returned untouched, so consult the PayMfi API documentation for full schemas.

## Development

```bash
composer install
composer test
```

## Security

Keep merchant credentials in environment/secret storage, never in source control. Use `Client::ENV_SANDBOX` until you are ready to go live.

## License

MIT. See [LICENSE](LICENSE).
