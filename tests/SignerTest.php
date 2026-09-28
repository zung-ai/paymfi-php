<?php

declare(strict_types=1);

namespace PayMfi\Tests;

use PayMfi\Signer;
use PHPUnit\Framework\TestCase;

final class SignerTest extends TestCase
{
    public function testPostSignatureMatchesFixedVector(): void
    {
        // Pins the wire format: "{timestamp}.{METHOD}.{path}.{rawBody}", HMAC-SHA256.
        $this->assertSame(
            'd22709be7493467c5ce1070f992cadfa66d70e7e03fb5a27fb98c69763c6fccb',
            Signer::sign(
                'test_secret',
                '1700000000',
                'POST',
                '/api/v1/initiate-payment',
                '{"payment_amount":100,"currency_code":"KES"}'
            )
        );
    }

    public function testGetSignsAnEmptyBody(): void
    {
        $this->assertSame(
            '66a01c3ffdf75ccc5d736432968c140dd62c8915ef53e68d91a9fd00d1cd2505',
            Signer::sign('test_secret', '1700000000', 'GET', '/api/v1/verify-payment/TRX123', '')
        );
    }

    public function testMethodIsUppercased(): void
    {
        $this->assertSame(
            Signer::sign('s', '1', 'POST', '/p', 'b'),
            Signer::sign('s', '1', 'post', '/p', 'b')
        );
    }

    public function testDifferentSecretsGiveDifferentSignatures(): void
    {
        $this->assertNotSame(
            Signer::sign('one', '1', 'GET', '/p', ''),
            Signer::sign('two', '1', 'GET', '/p', '')
        );
    }
}
