<?php

declare(strict_types=1);

namespace App\Benchmark;

class BenchmarkRunner
{
    private const PRIMARY_BASE_URL = 'http://nginx';
    private const FALLBACK_BASE_URL = 'http://127.0.0.1:8080';
    private const STRATEGIES = ['baseline', 'pessimistic', 'optimistic', 'mutex', 'atomic'];

    public function __construct(private readonly ConsistencyAuditor $auditor) {}

    public function run(
        string $strategy,
        int $orderId,
        int $userId,
        int $totalRequests,
        int $concurrency,
        int $paymentAmountCents,
    ): BenchmarkResult {
        if ($totalRequests < 1 || $concurrency < 1 || $paymentAmountCents < 1) {
            throw new \InvalidArgumentException('Requests, concurrency, and payment amount must be positive integers.');
        }

        $intents = [];
        for ($i = 0; $i < $totalRequests; ++$i) {
            $intents[] = new PaymentIntent(
                userId: $userId,
                amountCents: $paymentAmountCents,
                paymentMethod: 'card',
                idempotencyKey: sprintf('benchmark-%d-%s', $i, bin2hex(random_bytes(16))),
            );
        }

        return $this->runConcurrentPayments($strategy, $orderId, $intents, $concurrency)->benchmark;
    }

    /** @param list<PaymentIntent> $intents */
    public function runConcurrentPayments(
        string $strategy,
        int $orderId,
        array $intents,
        int $concurrency,
    ): MultiUserSimulationResult {
        if (!extension_loaded('curl')) {
            throw new \RuntimeException('The PHP cURL extension is required to run benchmarks.');
        }
        if (!in_array($strategy, self::STRATEGIES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown payment strategy "%s".', $strategy));
        }
        if ($orderId < 1 || $concurrency < 1 || $intents === []) {
            throw new \InvalidArgumentException('Order ID and concurrency must be positive integers, and intents must not be empty.');
        }

        $intents = array_values($intents);
        $idempotencyKeys = [];
        $distinctUsers = [];

        foreach ($intents as $index => $intent) {
            if (!$intent instanceof PaymentIntent) {
                throw new \InvalidArgumentException('Each payment intent must be an instance of PaymentIntent.');
            }

            $idempotencyKeys[$index] = $intent->idempotencyKey !== null && trim($intent->idempotencyKey) !== ''
                ? $intent->idempotencyKey
                : sprintf('multi-%d-%s', $index, bin2hex(random_bytes(12)));
            $distinctUsers[$intent->userId] = true;
        }

        $totalRequests = count($intents);
        $startedAt = hrtime(true);
        $latencies = [];
        $statusCounts = ['201' => 0, '409' => 0, '422' => 0, '5xx' => 0];
        $attempts = [];

        for ($offset = 0; $offset < $totalRequests; $offset += $concurrency) {
            $batchSize = min($concurrency, $totalRequests - $offset);
            $batchIntents = array_slice($intents, $offset, $batchSize);
            $batchKeys = array_slice($idempotencyKeys, $offset, $batchSize);

            $batch = $this->sendBatch(self::PRIMARY_BASE_URL, $orderId, $strategy, $batchIntents, $batchKeys);

            if ($batch['transportFailures'] === $batchSize) {
                $batch = $this->sendBatch(self::FALLBACK_BASE_URL, $orderId, $strategy, $batchIntents, $batchKeys);
            }

            foreach ($batch['responses'] as $localIndex => $response) {
                $intent = $batchIntents[$localIndex];
                $resolvedKey = $batchKeys[$localIndex];
                $status = $response['status'];
                $latencyMs = $response['latencyMs'];

                $latencies[] = $latencyMs;
                if ($status === 201) {
                    ++$statusCounts['201'];
                } elseif ($status === 409) {
                    ++$statusCounts['409'];
                } elseif ($status === 422) {
                    ++$statusCounts['422'];
                } elseif ($status >= 500 || $status === 0) {
                    ++$statusCounts['5xx'];
                }

                $attempts[] = new ConcurrentPaymentAttemptResult(
                    index: $offset + $localIndex,
                    userId: $intent->userId,
                    participantLabel: $intent->participantLabel,
                    amountCents: $intent->amountCents,
                    paymentMethod: $intent->paymentMethod,
                    idempotencyKey: $resolvedKey,
                    httpStatus: $status,
                    latencyMs: $latencyMs,
                    paymentId: $response['paymentId'],
                    errorMessage: $response['errorMessage'],
                );
            }
        }

        $totalTimeSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;
        sort($latencies, SORT_NUMERIC);
        $audit = $this->auditor->audit($orderId);

        $benchmark = new BenchmarkResult(
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

        return new MultiUserSimulationResult(
            strategy: $strategy,
            orderId: $orderId,
            distinctUserCount: count($distinctUsers),
            benchmark: $benchmark,
            attempts: $attempts,
        );
    }

    /**
     * @param list<PaymentIntent> $intents
     * @param list<string>        $idempotencyKeys
     *
     * @return array{
     *     responses: list<array{status: int, latencyMs: float, paymentId: ?int, errorMessage: ?string}>,
     *     transportFailures: int
     * }
     */
    private function sendBatch(
        string $baseUrl,
        int $orderId,
        string $strategy,
        array $intents,
        array $idempotencyKeys,
    ): array {
        $multiHandle = curl_multi_init();
        $handles = [];

        foreach ($intents as $index => $intent) {
            $handle = curl_init(sprintf('%s/payments/%d', $baseUrl, $orderId));
            $payload = json_encode([
                'amountCents' => $intent->amountCents,
                'paymentMethod' => $intent->paymentMethod,
                'idempotencyKey' => $idempotencyKeys[$index],
                'userId' => $intent->userId,
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
            $rawBody = curl_multi_getcontent($handle);

            if (curl_errno($handle) !== 0 || $status === 0) {
                ++$transportFailures;
            }

            $decoded = null;
            if (is_string($rawBody) && $rawBody !== '') {
                $candidate = json_decode($rawBody, true);
                if (is_array($candidate)) {
                    $decoded = $candidate;
                }
            }

            $paymentId = null;
            $errorMessage = null;

            if ($status === 201 && is_array($decoded) && isset($decoded['id'])) {
                $paymentId = (int) $decoded['id'];
            } elseif ($status >= 400 || $status === 0) {
                $rawError = null;
                if (is_array($decoded)) {
                    $candidateError = $decoded['error'] ?? $decoded['detail'] ?? $decoded['title'] ?? null;
                    if (is_string($candidateError) && trim($candidateError) !== '') {
                        $rawError = trim($candidateError);
                    }
                }
                $errorMessage = $rawError ?? ($status === 0 ? 'Transport failure' : sprintf('HTTP %d', $status));
                if (strlen($errorMessage) > 500) {
                    $truncated = iconv_substr($errorMessage, 0, 497, 'UTF-8');
                    $errorMessage = ($truncated !== false ? $truncated : substr($errorMessage, 0, 497)) . '...';
                }
            }

            $responses[] = [
                'status' => $status,
                'latencyMs' => $latencyMs,
                'paymentId' => $paymentId,
                'errorMessage' => $errorMessage,
            ];
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
