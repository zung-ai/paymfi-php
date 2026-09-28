<?php

declare(strict_types=1);

namespace PayMfi\Tests;

use PayMfi\Exception\InvalidPayloadException;
use PayMfi\Exception\SignatureVerificationException;
use PayMfi\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const BODY = '{"event":"payment.success","trx_id":"TRX123"}';
    private const SECRET = 'whsec_test';
    private const SIGNATURE = '302362d33378038f62cd14cac4130b94f0d9bcc823e3deecec49d6a2c66eb475';

    public function testAcceptsPrefixedSignature(): void
    {
        $this->assertTrue(Webhook::verifySignature(self::BODY, 'sha256=' . self::SIGNATURE, self::SECRET));
    }

    public function testAcceptsBareSignature(): void
    {
        $this->assertTrue(Webhook::verifySignature(self::BODY, self::SIGNATURE, self::SECRET));
    }

    public function testAcceptsUppercaseHex(): void
    {
        $this->assertTrue(Webhook::verifySignature(self::BODY, strtoupper(self::SIGNATURE), self::SECRET));
    }

    public function testRejectsWrongSecret(): void
    {
        $this->assertFalse(Webhook::verifySignature(self::BODY, self::SIGNATURE, 'another_secret'));
    }

    public function testRejectsTamperedBody(): void
    {
        $this->assertFalse(Webhook::verifySignature('{"event":"payment.success","trx_id":"TRX999"}', self::SIGNATURE, self::SECRET));
    }

    public function testRejectsReencodedBody(): void
    {
        // Same data, different bytes (pretty-printed): must NOT verify.
        $reencoded = json_encode(json_decode(self::BODY, true), JSON_PRETTY_PRINT);

        $this->assertFalse(Webhook::verifySignature($reencoded, self::SIGNATURE, self::SECRET));
    }

    public function testRejectsMissingOrEmptyInputs(): void
    {
        $this->assertFalse(Webhook::verifySignature(self::BODY, null, self::SECRET));
        $this->assertFalse(Webhook::verifySignature(self::BODY, '', self::SECRET));
        $this->assertFalse(Webhook::verifySignature(self::BODY, 'sha256=', self::SECRET));
        $this->assertFalse(Webhook::verifySignature(self::BODY, self::SIGNATURE, ''));
    }

    public function testConstructEventReturnsDecodedPayload(): void
    {
        $event = Webhook::constructEvent(self::BODY, 'sha256=' . self::SIGNATURE, self::SECRET);

        $this->assertSame(['event' => 'payment.success', 'trx_id' => 'TRX123'], $event);
    }

    public function testConstructEventThrowsOnBadSignature(): void
    {
        $this->expectException(SignatureVerificationException::class);

        Webhook::constructEvent(self::BODY, 'sha256=deadbeef', self::SECRET);
    }

    public function testConstructEventThrowsWhenSignedBodyIsNotAJsonObject(): void
    {
        $body = 'not json';
        $signature = hash_hmac('sha256', $body, self::SECRET);

        $this->expectException(InvalidPayloadException::class);

        Webhook::constructEvent($body, $signature, self::SECRET);
    }
}
