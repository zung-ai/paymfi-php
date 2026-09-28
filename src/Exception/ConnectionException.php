<?php

declare(strict_types=1);

namespace PayMfi\Exception;

/**
 * The request never got a response (DNS failure, refused connection,
 * timeout, TLS failure...).
 */
final class ConnectionException extends \RuntimeException implements PayMfiExceptionInterface
{
}
