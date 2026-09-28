<?php

declare(strict_types=1);

namespace PayMfi;

use PayMfi\Exception\InvalidPayloadException;
use PayMfi\Exception\SignatureVerificationException;

/**
 * Verification of inbound PayMfi webhooks (IPN).
 *
 * IMPORTANT: pass the RAW request body, exactly as received. Parsing the
 * JSON and re-encoding it changes the bytes and breaks the signature.
 *
 * Use the webhook secret of the merchant account the notification belongs
 * to; each merchant signs with its own secret.
 *
 * Signature verification proves the sender knows the secret; it does not
 * by itself stop a valid notification from being delivered more than once,
 * so make your fulfillment idempotent (e.g. key it on the transaction id).
 */
final class Webhook
{
    /** HTTP header carrying the signature. */
    public const SIGNATURE_HEADER = 'X-Signature';

    /**
     * @param string|null $signatureHeader Value of the X-Signature header ("sha256=<hex>" or bare "<hex>").
     */
    public static function verifySignature(string $rawBody, ?string $signatureHeader, string $webhookSecret): bool
    {
        if ($webhookSecret === '' || $signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        $signature = str_starts_with($signatureHeader, 'sha256=')
            ? substr($signatureHeader, 7)
            : $signatureHeader;

        if ($signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $webhookSecret), strtolower($signature));
    }

    /**
     * Verifies the signature, then decodes the body.
     *
     * @return array<mixed>
     *
     * @throws SignatureVerificationException When the signature is missing or wrong.
     * @throws InvalidPayloadException        When the (correctly signed) body is not a JSON object.
     */
    public static function constructEvent(string $rawBody, ?string $signatureHeader, string $webhookSecret): array
    {
        if (!self::verifySignature($rawBody, $signatureHeader, $webhookSecret)) {
            throw new SignatureVerificationException('Webhook signature verification failed.');
        }

        $event = json_decode($rawBody, true);

        if (!is_array($event)) {
            throw new InvalidPayloadException('Webhook body is not a valid JSON object.');
        }

        return $event;
    }
}
