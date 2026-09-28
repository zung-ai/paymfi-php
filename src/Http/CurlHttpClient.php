<?php

declare(strict_types=1);

namespace PayMfi\Http;

use PayMfi\Exception\ConnectionException;

/**
 * Default transport, built on ext-curl only (no third-party dependencies).
 *
 * Redirects are deliberately NOT followed: a signed request must never be
 * silently replayed against a different location.
 */
final class CurlHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly int $timeout = 30,
        private readonly int $connectTimeout = 10,
    ) {
    }

    public function request(string $method, string $url, array $headers, ?string $body = null): Response
    {
        $ch = curl_init();
        if ($ch === false) {
            throw new ConnectionException('Unable to initialise cURL.');
        }

        $formattedHeaders = [];
        foreach ($headers as $name => $value) {
            $formattedHeaders[] = $name . ': ' . $value;
        }

        $responseHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);

        if ($raw === false) {
            $errno = curl_errno($ch);

            throw new ConnectionException(
                sprintf('Could not reach PayMfi: %s (cURL error %d)', curl_error($ch), $errno),
                $errno
            );
        }

        return new Response((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), (string) $raw, $responseHeaders);
    }
}
