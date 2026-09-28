<?php

declare(strict_types=1);

namespace PayMfi\Exception;

/**
 * A webhook body passed signature verification but is not a JSON object.
 */
final class InvalidPayloadException extends \UnexpectedValueException implements PayMfiExceptionInterface
{
}
