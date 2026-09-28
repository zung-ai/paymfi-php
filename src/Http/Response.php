<?php

declare(strict_types=1);

namespace PayMfi\Http;

final class Response
{
    /**
     * @param array<string, string> $headers Response headers, names lower-cased.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }
}
