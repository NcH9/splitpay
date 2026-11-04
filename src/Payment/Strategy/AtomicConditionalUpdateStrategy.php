<?php

declare(strict_types=1);

namespace App\Payment\Strategy;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Payment\PaymentResult;
use App\Payment\PaymentStrategyInterface;

/**
 * Strategy 5 — Atomic Conditional UPDATE.
 *
 * Reserves the payment amount with a single atomic SQL statement:
 *
 *   UPDATE orders
 *   SET paid_price_cents = paid_price_cents + :amount
 *   WHERE id = :id
 *     AND paid_price_cents + :amount <= full_price_cents
 *
 * Result:
 *   1 affected row  → amount reserved; create Payment record
 *   0 affected rows → insufficient balance; throw OverpaymentException
 *
 * This is the most performant strategy under high contention because it
 * never blocks and relies entirely on the database's atomic write semantics.
 *
 * Implementation: Section 3.
 */
final class AtomicConditionalUpdateStrategy implements PaymentStrategyInterface
{
    public function getName(): string
    {
        return 'atomic';
    }

    public function processPayment(
        Order $order,
        User $user,
        int $amountCents,
        PaymentMethod $paymentMethod,
        string $idempotencyKey,
    ): PaymentResult {
        throw new \LogicException('AtomicConditionalUpdateStrategy::processPayment() is not yet implemented. Coming in Section 3.');
    }
}
