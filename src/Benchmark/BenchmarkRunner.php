<?php

declare(strict_types=1);

namespace App\Benchmark;

class BenchmarkRunner
{
    private const PRIMARY_BASE_URL = 'http://nginx';
    private const FALLBACK_BASE_URL = 'http://127.0.0.1:8080';

    public function __construct(private readonly ConsistencyAuditor $auditor) {}

    public function run(
        string $strategy,
        int $orderId,
        int $userId,
        int $totalRequests,
        int $concurrency,
        int $paymentAmountCents,
    ): BenchmarkResult {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('The PHP cURL extension is required to run benchmarks.');
        }
        if ($totalRequests < 1 || $concurrency < 1 || $paymentAmountCents < 1) {
            throw new \InvalidArgumentException('Requests, concurrency, and payment amount must be positive integers.');
        }

        $endpointStrategy = $strategy === 'symfony_lock' ? 'mutex' : $strategy;
        $startedAt = hrtime(true);
        $latencies = [];
        $statusCounts = ['201' => 0, '409' => 0, '422' => 0, '5xx' => 0];

        for ($offset = 0; $offset < $totalRequests; $offset += $concurrency) {
            $batchSize = min($concurrency, $totalRequests - $offset);
            $batch = $this->sendBatch(self::PRIMARY_BASE_URL, $orderId, $userId, $endpointStrategy, $paymentAmountCents, $offset, $batchSize);

            if ($batch['transportFailures'] === $batchSize) {
                $batch = $this->sendBatch(self::FALLBACK_BASE_URL, $orderId, $userId, $endpointStrategy, $paymentAmountCents, $offset, $batchSize);
            }

            foreach ($batch['responses'] as $response) {
                $latencies[] = $response['latencyMs'];
                $status = $response['status'];
                if ($status === 201) {
                    ++$statusCounts['201'];
                } elseif ($status === 409) {
                    ++$statusCounts['409'];
                } elseif ($status === 422) {
                    ++$statusCounts['422'];
                } elseif ($status >= 500 || $status === 0) {
                    ++$statusCounts['5xx'];
                }
            }
        }

        $totalTimeSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        sort($latencies, SORT_NUMERIC);
        $audit = $this->auditor->audit($orderId);

        return new BenchmarkResult(
            strategy: $strategy,
            totalRequests: $totalRequests,
            concurrency: min($concurrency, $totalRequests),
            totalTimeSeconds: $totalTimeSeconds,
            requestsPerSecond: $totalTimeSeconds > 0 ? $totalRequests / $totalTimeSeconds : 0.0,
            status201Count: $statusCounts['201'],
            status409Count: $statusCounts['409'],
            status422Count: $statusCounts['422'],
            status5xxCount: $statusCounts['5xx'],
            latencyMinMs: $latencies[0] ?? 0.0,
            latencyP50Ms: $this->percentile($latencies, 0.50),
            latencyP95Ms: $this->percentile($latencies, 0.95),
            latencyP99Ms: $this->percentile($latencies, 0.99),
            latencyMaxMs: $latencies === [] ? 0.0 : $latencies[array_key_last($latencies)],
            audit: $audit,
        );
    }

    /** @return array{responses: list<array{status: int, latencyMs: float}>, transportFailures: int} */
    private function sendBatch(string $baseUrl, int $orderId, int $userId, string $strategy, int $amountCents, int $offset, int $count): array
    {
        $multiHandle = curl_multi_init();
        $handles = [];

        for ($index = 0; $index < $count; ++$index) {
            $handle = curl_init(sprintf('%s/payments/%d', $baseUrl, $orderId));
            $payload = json_encode([
                'amountCents' => $amountCents,
                'paymentMethod' => 'card',
                'idempotencyKey' => sprintf('benchmark-%d-%s', $offset + $index, bin2hex(random_bytes(16))),
                'userId' => $userId,
                'strategy' => $strategy,
            ], JSON_THROW_ON_ERROR);

            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 60,
            ]);
            curl_multi_add_handle($multiHandle, $handle);
            $handles[] = $handle;
        }

        $running = null;
        do {
            $multiStatus = curl_multi_exec($multiHandle, $running);
            if ($multiStatus === CURLM_CALL_MULTI_PERFORM) {
                continue;
            }
            if ($running > 0 && curl_multi_select($multiHandle, 1.0) === -1) {
                usleep(1000);
            }
        } while ($running > 0 && $multiStatus === CURLM_OK);

        $responses = [];
        $transportFailures = 0;
        foreach ($handles as $handle) {
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $latencyMs = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;
            if (curl_errno($handle) !== 0 || $status === 0) {
                ++$transportFailures;
            }
            $responses[] = ['status' => $status, 'latencyMs' => $latencyMs];
            curl_multi_remove_handle($multiHandle, $handle);
            curl_close($handle);
        }
        curl_multi_close($multiHandle);

        return ['responses' => $responses, 'transportFailures' => $transportFailures];
    }

    /** @param list<float> $sortedLatencies */
    private function percentile(array $sortedLatencies, float $percentile): float
    {
        if ($sortedLatencies === []) {
            return 0.0;
        }

        $index = max(0, (int) ceil($percentile * count($sortedLatencies)) - 1);

        return $sortedLatencies[$index];
    }
}
