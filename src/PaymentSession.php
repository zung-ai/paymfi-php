<?php

declare(strict_types=1);

namespace PayMfi;

/**
 * Result of {@see Client::initiatePayment()}: send the customer to
 * `$paymentUrl` to complete checkout, and keep `$reference` to match the
 * payment up later.
 */
final class PaymentSession
{
    /**
     * @param array<mixed> $raw The full decoded API response.
     */
    public function __construct(
        public readonly string $paymentUrl,
        public readonly string $reference,
        public readonly array $raw,
    ) {
    }
}
