<?php

declare(strict_types=1);

namespace PayMfi\Exception;

/**
 * Marker interface implemented by every exception this library throws, so
 * callers can catch everything from PayMfi with a single `catch`.
 */
interface PayMfiExceptionInterface extends \Throwable
{
}
