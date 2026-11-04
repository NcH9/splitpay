<?php

declare(strict_types=1);

namespace App\Payment\Strategy;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Payment\PaymentResult;
use App\Payment\PaymentStrategyInterface;

/**
 * Strategy 1 — Baseline (no synchronization).
 *
 * Intentionally provides NO concurrency protection.
 * Under concurrent load this will expose race conditions:
 *   - lost updates to Order.paid_price_cents
 *   - overpayments (invariant I1 violated)
 *
 * This is the control group for the benchmark experiment.
 *
 * Implementation: Section 3.
 */
final class BaselineStrategy implements PaymentStrategyInterface
{
    public function getName(): string
    {
        return 'baseline';
    }

    public function processPayment(
        Order $order,
        User $user,
        int $amountCents,
        PaymentMethod $paymentMethod,
        string $idempotencyKey,
    ): PaymentResult {
        throw new \LogicException('BaselineStrategy::processPayment() is not yet implemented. Coming in Section 3.');
    }
}
