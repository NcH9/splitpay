<?php

declare(strict_types=1);

namespace App\Benchmark;

final readonly class AuditResult
{
    public function __construct(
        public int $orderId,
        public int $orderFullPriceCents,
        public int $orderPaidPriceCents,
        public int $actualPaymentSumCents,
        public int $paymentCount,
        public bool $isConsistent,
        public bool $isOverpaid,
        public int $discrepancyCents,
    ) {}
}
