<?php

declare(strict_types=1);

namespace App\Payment\Strategy;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Payment\PaymentResult;
use App\Payment\PaymentStrategyInterface;

/**
 * Strategy 2 — Pessimistic Lock.
 *
 * Acquires a database row-level write lock on the Order row before reading
 * the balance. All concurrent requests block until the lock is released.
 *
 * SQL equivalent:
 *   SELECT ... FROM orders WHERE id = :id FOR UPDATE;
 *
 * Doctrine equivalent:
 *   $em->lock($order, LockMode::PESSIMISTIC_WRITE);
 *
 * Implementation: Section 3.
 */
final class PessimisticLockStrategy implements PaymentStrategyInterface
{
    public function getName(): string
    {
        return 'pessimistic';
    }

    public function processPayment(
        Order $order,
        User $user,
        int $amountCents,
        PaymentMethod $paymentMethod,
        string $idempotencyKey,
    ): PaymentResult {
        throw new \LogicException('PessimisticLockStrategy::processPayment() is not yet implemented. Coming in Section 3.');
    }
}
