<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Payment;

/**
 * Value object returned by every PaymentStrategyInterface implementation.
 * Carries the persisted Payment entity plus execution metadata useful
 * for the benchmark harness (Section 4).
 */
final readonly class PaymentResult
{
    public function __construct(
        /** The newly created and persisted Payment entity. */
        public Payment $payment,

        /** The strategy that produced this result. */
        public string $strategyName,

        /**
         * Number of retries performed before success.
         * Always 0 for non-retry strategies (baseline, pessimistic, mutex, atomic).
         * May be > 0 for OptimisticLockStrategy.
         */
        public int $retriesAttempted = 0,
    ) {}
}
