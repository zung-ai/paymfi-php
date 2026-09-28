<?php

declare(strict_types=1);

namespace PayMfi\Tests;

use PayMfi\Client;
use PayMfi\Exception\ApiException;
use PayMfi\Exception\ConnectionException;
use PayMfi\Exception\InvalidArgumentException;
use PayMfi\Http\Response;
use PayMfi\Signer;
use PayMfi\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    private function client(string $environment = Client::ENV_SANDBOX): Client
    {
        return new Client('mk_test', 'ak_test', 'client_secret_test', $environment, Client::DEFAULT_BASE_URL, $this->http);
    }

    /**
     * @return array<string, mixed>
     */
    private function validParams(array $overrides = []): array
    {
        return array_merge([
            'amount' => 250.5,
            'currency' => 'kes',
            'success_url' => 'https://shop.example/return',
            'ipn_url' => 'https://shop.example/webhooks/paymfi',
            'reference' => 'ORDER-1001',
            'description' => 'Wallet top-up',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
        ], $overrides);
    }

    private function assertRequestIsValidlySigned(array $request): void
    {
        $header = $request['headers']['X-Signature'];
        $this->assertStringStartsWith('sha256=', $header);

        $path = parse_url($request['url'], PHP_URL_PATH);
        $expected = Signer::sign(
            'client_secret_test',
            $request['headers']['X-Timestamp'],
            $request['method'],
            $path,
            $request['body'] ?? ''
        );

        $this->assertSame($expected, substr($header, 7));
    }

    public function testInitiatePaymentSendsSignedRequestAndReturnsSession(): void
    {
        $this->http->queueJson(200, ['payment_url' => 'https://paymfi.com/pay/abc', 'status' => 'pending']);

        $session = $this->client()->initiatePayment($this->validParams());

        $this->assertSame('https://paymfi.com/pay/abc', $session->paymentUrl);
        $this->assertSame('ORDER-1001', $session->reference);
        $this->assertSame('pending', $session->raw['status']);

        $request = $this->http->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://paymfi.com/api/v1/initiate-payment', $request['url']);
        $this->assertSame('mk_test', $request['headers']['X-Merchant-Key']);
        $this->assertSame('ak_test', $request['headers']['X-API-Key']);
        $this->assertSame('sandbox', $request['headers']['X-Environment']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertRequestIsValidlySigned($request);

        $body = json_decode($request['body'], true);
        $this->assertSame(250.5, $body['payment_amount']);
        $this->assertSame('KES', $body['currency_code'], 'currency is upper-cased');
        $this->assertSame('ORDER-1001', $body['ref_trx']);
        $this->assertSame('https://shop.example/return', $body['success_redirect']);
        $this->assertSame('https://shop.example/webhooks/paymfi', $body['ipn_url']);
        $this->assertSame('jane@example.com', $body['customer_email']);
    }

    public function testBodyKeepsSlashesUnescaped(): void
    {
        $this->http->queueJson(200, ['payment_url' => 'https://paymfi.com/pay/abc']);

        $this->client()->initiatePayment($this->validParams());

        $this->assertStringContainsString('https://shop.example/return', $this->http->lastRequest()['body']);
    }

    public function testCancelUrlDefaultsToSuccessUrl(): void
    {
        $this->http->queueJson(200, ['payment_url' => 'https://paymfi.com/pay/abc']);

        $this->client()->initiatePayment($this->validParams());

        $body = json_decode($this->http->lastRequest()['body'], true);
        $this->assertSame($body['success_redirect'], $body['cancel_redirect']);
    }

    public function testReferenceIsGeneratedWhenOmitted(): void
    {
        $this->http->queueJson(200, ['payment_url' => 'https://paymfi.com/pay/abc']);

        $params = $this->validParams();
        unset($params['reference']);
        $session = $this->client()->initiatePayment($params);

        $this->assertMatchesRegularExpression('/^PMF[0-9A-F]{16}$/', $session->reference);
        $this->assertSame($session->reference, json_decode($this->http->lastRequest()['body'], true)['ref_trx']);
    }

    public function testProductionEnvironmentIsSentAsHeader(): void
    {
        $this->http->queueJson(200, ['payment_url' => 'https://paymfi.com/pay/abc']);

        $this->client(Client::ENV_PRODUCTION)->initiatePayment($this->validParams());

        $this->assertSame('production', $this->http->lastRequest()['headers']['X-Environment']);
    }

    public function testMissingPaymentUrlThrowsWithApiMessage(): void
    {
        $this->http->queueJson(200, ['message' => 'Invalid merchant']);

        try {
            $this->client()->initiatePayment($this->validParams());
            $this->fail('Expected ApiException.');
        } catch (ApiException $e) {
            $this->assertSame('Invalid merchant', $e->getMessage());
            $this->assertSame(['message' => 'Invalid merchant'], $e->getBody());
        }
    }

    public function testNon2xxThrowsApiExceptionWithStatusAndBody(): void
    {
        $this->http->queueJson(401, ['message' => 'Bad signature']);

        try {
            $this->client()->initiatePayment($this->validParams());
            $this->fail('Expected ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('Bad signature', $e->getMessage());
        }
    }

    public function testNon2xxWithoutJsonFallsBackToGenericMessage(): void
    {
        $this->http->queue(new Response(502, '<html>Bad gateway</html>'));

        try {
            $this->client()->siteInfo();
            $this->fail('Expected ApiException.');
        } catch (ApiException $e) {
            $this->assertSame(502, $e->getStatusCode());
            $this->assertSame('PayMfi API returned HTTP 502.', $e->getMessage());
        }
    }

    public function testSuccessWithInvalidJsonThrows(): void
    {
        $this->http->queue(new Response(200, 'not json'));

        $this->expectException(ApiException::class);

        $this->client()->siteInfo();
    }

    public function testVerifyPaymentSignsAnEmptyBodyGet(): void
    {
        $this->http->queueJson(200, ['status' => 'completed', 'trx_id' => 'TRX123']);

        $result = $this->client()->verifyPayment('TRX123');

        $this->assertSame('completed', $result['status']);

        $request = $this->http->lastRequest();
        $this->assertSame('GET', $request['method']);
        $this->assertSame('https://paymfi.com/api/v1/verify-payment/TRX123', $request['url']);
        $this->assertNull($request['body']);
        $this->assertArrayNotHasKey('Content-Type', $request['headers']);
        $this->assertRequestIsValidlySigned($request);
    }

    public function testVerifyPaymentEncodesTheTransactionIdInThePath(): void
    {
        $this->http->queueJson(200, []);

        $this->client()->verifyPayment('a/b c');

        $this->assertSame('https://paymfi.com/api/v1/verify-payment/a%2Fb%20c', $this->http->lastRequest()['url']);
        $this->assertRequestIsValidlySigned($this->http->lastRequest());
    }

    public function testTestConnectionSucceedsForActiveSite(): void
    {
        $this->http->queueJson(200, ['status' => 'active', 'site_name' => 'Acme SACCO']);

        $result = $this->client()->testConnection();

        $this->assertTrue($result->success);
        $this->assertSame('Connected successfully to Acme SACCO.', $result->message);
        $this->assertSame('https://paymfi.com/api/v1/site-info', $this->http->lastRequest()['url']);
    }

    public function testTestConnectionReportsInactiveSite(): void
    {
        $this->http->queueJson(200, ['status' => 'suspended', 'message' => 'Site suspended']);

        $result = $this->client()->testConnection();

        $this->assertFalse($result->success);
        $this->assertSame('Site suspended', $result->message);
    }

    public function testTestConnectionNeverThrowsForApiOrNetworkErrors(): void
    {
        $this->http->queueJson(401, ['message' => 'Bad credentials']);
        $this->http->queue(new ConnectionException('Could not reach PayMfi: timeout'));

        $first = $this->client()->testConnection();
        $second = $this->client()->testConnection();

        $this->assertFalse($first->success);
        $this->assertSame('Bad credentials', $first->message);
        $this->assertFalse($second->success);
        $this->assertStringContainsString('timeout', $second->message);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInitiateParams(): array
    {
        return [
            'missing amount' => [['amount' => null], 'amount'],
            'non-numeric amount' => [['amount' => 'abc'], 'amount'],
            'zero amount' => [['amount' => 0], 'greater than zero'],
            'negative amount' => [['amount' => -5], 'greater than zero'],
            'missing currency' => [['currency' => ''], 'currency'],
            'missing success_url' => [['success_url' => ''], 'success_url'],
            'missing ipn_url' => [['ipn_url' => null], 'ipn_url'],
            'non-string description' => [['description' => ['x']], 'description'],
            'unknown key (typo)' => [['callback_url' => 'https://x.example'], 'Unknown parameter'],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidInitiateParams')]
    public function testInvalidParamsAreRejectedBeforeAnyRequest(array $overrides, string $expectedMessagePart): void
    {
        try {
            $this->client()->initiatePayment($this->validParams($overrides));
            $this->fail('Expected InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($expectedMessagePart, $e->getMessage());
        }

        $this->assertSame([], $this->http->requests, 'no HTTP request must be made');
    }

    public function testVerifyPaymentRejectsEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client()->verifyPayment('  ');
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function invalidConstructorArgs(): array
    {
        return [
            'empty merchant key' => ['', 'ak', 'cs', 'sandbox'],
            'empty api key' => ['mk', ' ', 'cs', 'sandbox'],
            'empty client secret' => ['mk', 'ak', '', 'sandbox'],
            'unknown environment' => ['mk', 'ak', 'cs', 'staging'],
        ];
    }

    #[DataProvider('invalidConstructorArgs')]
    public function testConstructorValidatesCredentialsAndEnvironment(string $mk, string $ak, string $cs, string $env): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client($mk, $ak, $cs, $env);
    }

    public function testConstructorRejectsInvalidBaseUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Client('mk', 'ak', 'cs', 'sandbox', 'not a url');
    }

    public function testCustomBaseUrlTrailingSlashIsNormalised(): void
    {
        $this->http->queueJson(200, ['status' => 'active']);

        (new Client('mk', 'ak', 'cs', 'sandbox', 'https://sandbox.paymfi.example/', $this->http))->siteInfo();

        $this->assertSame('https://sandbox.paymfi.example/api/v1/site-info', $this->http->lastRequest()['url']);
    }
}
