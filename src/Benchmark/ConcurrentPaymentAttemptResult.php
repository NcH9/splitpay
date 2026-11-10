<?php

declare(strict_types=1);

namespace App\Benchmark;

final readonly class ConcurrentPaymentAttemptResult
{
    public function __construct(
        public int $index,
        public int $userId,
        public ?string $participantLabel,
        public int $amountCents,
        public string $paymentMethod,
        public string $idempotencyKey,
        public int $httpStatus,
        public float $latencyMs,
        public ?int $paymentId = null,
        public ?string $errorMessage = null,
    ) {}
}
