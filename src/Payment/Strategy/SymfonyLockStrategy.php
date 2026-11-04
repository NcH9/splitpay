<?php

declare(strict_types=1);

namespace App\Payment\Strategy;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Payment\PaymentResult;
use App\Payment\PaymentStrategyInterface;

/**
 * Strategy 4 — Application Mutex (Symfony Lock).
 *
 * Acquires a process-level mutex keyed on the order ID before entering
 * the critical section:
 *   Lock key: "order:{orderId}"
 *
 * Unlike pessimistic locking (which is DB-level), this lock lives at
 * the application layer. Can be backed by any symfony/lock store.
 * The initial implementation uses the default store (filesystem/flock).
 *
 * Requires: symfony/lock (installed in Section 3 if not present).
 *
 * Implementation: Section 3.
 */
final class SymfonyLockStrategy implements PaymentStrategyInterface
{
    public function getName(): string
    {
        return 'mutex';
    }

    public function processPayment(
        Order $order,
        User $user,
        int $amountCents,
        PaymentMethod $paymentMethod,
        string $idempotencyKey,
    ): PaymentResult {
        throw new \LogicException('SymfonyLockStrategy::processPayment() is not yet implemented. Coming in Section 3.');
    }
}
