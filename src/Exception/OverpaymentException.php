<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when a payment would cause Order.paid_price_cents to exceed Order.full_price_cents.
 * Maps to: HTTP 422 Unprocessable Entity.
 * Invariants violated: I1, I3.
 */
final class OverpaymentException extends \RuntimeException
{
    public function __construct(int $orderId, int $requested, int $remaining)
    {
        parent::__construct(sprintf(
            'Payment of %d cents would exceed the remaining balance of %d cents on order #%d.',
            $requested,
            $remaining,
            $orderId,
        ));
    }
}
