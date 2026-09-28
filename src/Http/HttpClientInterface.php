<?php

declare(strict_types=1);

namespace PayMfi\Http;

use PayMfi\Exception\ConnectionException;

/**
 * Minimal transport contract. The default implementation is
 * {@see CurlHttpClient}; implement this to route requests through your own
 * HTTP stack (or to fake the network in tests).
 *
 * Implementations MUST send `$body` byte-for-byte as given: the request
 * signature is computed over those exact bytes.
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     *
     * @throws ConnectionException When no HTTP response could be obtained.
     */
    public function request(string $method, string $url, array $headers, ?string $body = null): Response;
}
