<?php

declare(strict_types=1);

namespace PayMfi\Tests;

use PayMfi\Exception\ConnectionException;
use PayMfi\Http\CurlHttpClient;
use PHPUnit\Framework\TestCase;

final class CurlHttpClientTest extends TestCase
{
    public function testRefusedConnectionSurfacesAsConnectionException(): void
    {
        // Port 1 on loopback: connection is refused immediately on every OS.
        $client = new CurlHttpClient(timeout: 5, connectTimeout: 2);

        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('Could not reach PayMfi');

        $client->request('GET', 'http://127.0.0.1:1/', []);
    }
}
