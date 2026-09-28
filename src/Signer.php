<?php

declare(strict_types=1);

namespace PayMfi;

/**
 * Request signing, exactly as PayMfi specifies it: HMAC-SHA256, keyed with
 * the merchant's client secret, over
 *
 *     "{timestamp}.{HTTP_METHOD}.{path_with_query}.{raw_body}"
 *
 * GET requests sign an empty raw body.
 */
final class Signer
{
    public static function sign(
        string $clientSecret,
        string $timestamp,
        string $method,
        string $pathWithQuery,
        string $rawBody,
    ): string {
        $payload = $timestamp . '.' . strtoupper($method) . '.' . $pathWithQuery . '.' . $rawBody;

        return hash_hmac('sha256', $payload, $clientSecret);
    }
}
