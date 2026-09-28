<?php

declare(strict_types=1);

namespace PayMfi\Exception;

/**
 * An inbound webhook's signature did not match. Treat the request as
 * untrusted: respond with a 4xx and do not act on its contents.
 */
final class SignatureVerificationException extends \RuntimeException implements PayMfiExceptionInterface
{
}
