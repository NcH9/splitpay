<?php

declare(strict_types=1);

namespace App\Benchmark;

final readonly class MultiUserSimulationResult
{
    /** @param list<ConcurrentPaymentAttemptResult> $attempts */
    public function __construct(
        public string $strategy,
        public int $orderId,
        public int $distinctUserCount,
        public BenchmarkResult $benchmark,
        public array $attempts,
    ) {}
}
