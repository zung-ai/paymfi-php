<?php

declare(strict_types=1);

namespace PayMfi;

/**
 * Result of {@see Client::testConnection()}: whether a set of credentials
 * is accepted, with a human-readable message suitable for a settings UI.
 */
final class ConnectionResult
{
    /**
     * @param array<mixed> $data The decoded API response, when there was one.
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly array $data = [],
    ) {
    }
}
