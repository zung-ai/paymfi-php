<?php

declare(strict_types=1);

namespace PayMfi\Tests\Support;

use PayMfi\Http\HttpClientInterface;
use PayMfi\Http\Response;

/**
 * Test double: records every request and replays queued responses (or
 * throws a queued exception).
 */
final class FakeHttpClient implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var list<Response|\Throwable> */
    private array $queue = [];

    public function queue(Response|\Throwable $next): self
    {
        $this->queue[] = $next;

        return $this;
    }

    public function queueJson(int $status, array $data): self
    {
        return $this->queue(new Response($status, json_encode($data, JSON_THROW_ON_ERROR)));
    }

    public function request(string $method, string $url, array $headers, ?string $body = null): Response
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \LogicException('FakeHttpClient: no response queued.');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /**
     * @return array{method: string, url: string, headers: array<string, string>, body: ?string}
     */
    public function lastRequest(): array
    {
        return $this->requests[array_key_last($this->requests)];
    }
}
