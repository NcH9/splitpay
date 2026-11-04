<?php

declare(strict_types=1);

namespace App\Payment\Strategy;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Payment\PaymentResult;
use App\Payment\PaymentStrategyInterface;

/**
 * Strategy 3 — Optimistic Lock.
 *
 * Uses Doctrine's built-in optimistic locking via the Order.version field.
 * On concurrent modification, Doctrine throws OptimisticLockException.
 * A configurable retry loop re-attempts the operation up to MAX_RETRIES times.
 *
 * The benchmark measures: conflict rate, retry count, throughput, latency.
 *
 * Implementation: Section 3.
 */
final class OptimisticLockStrategy implements PaymentStrategyInterface
{
    public const int MAX_RETRIES = 3;

    public function getName(): string
    {
        return 'optimistic';
    }

    public function processPayment(
        Order $order,
        User $user,
        int $amountCents,
        PaymentMethod $paymentMethod,
        string $idempotencyKey,
    ): PaymentResult {
        throw new \LogicException('OptimisticLockStrategy::processPayment() is not yet implemented. Coming in Section 3.');
    }
}
