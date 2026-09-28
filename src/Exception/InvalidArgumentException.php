<?php

declare(strict_types=1);

namespace PayMfi\Exception;

/**
 * The caller passed something the library can tell is wrong before any
 * network request is made (missing credentials, non-numeric amount...).
 */
final class InvalidArgumentException extends \InvalidArgumentException implements PayMfiExceptionInterface
{
}
