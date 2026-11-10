<?php

declare(strict_types=1);

namespace App\Benchmark;

final readonly class BenchmarkResult
{
    public function __construct(
        public string $strategy,
        public int $totalRequests,
        public int $concurrency,
        public float $totalTimeSeconds,
        public float $requestsPerSecond,
        public int $status201Count,
        public int $status409Count,
        public int $status422Count,
        public int $status5xxCount,
        public float $latencyMinMs,
        public float $latencyP50Ms,
        public float $latencyP95Ms,
        public float $latencyP99Ms,
        public float $latencyMaxMs,
        public AuditResult $audit,
    ) {}
}
