<?php

declare(strict_types=1);

namespace App\Payment\Idempotency;

use App\Exception\IdempotencyConflictException;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Distributed Redis-backed idempotency filter following the Stripe idempotency pattern.
 *
 * Intercepts duplicate requests at memory speed using atomic Redis primitives (SET NX EX)
 * to eliminate duplicate execution before PostgreSQL transactions or entity queries run.
 *
 * Lifecycle:
 *   1. acquire(): SET idempotency:{key} "processing" NX EX 60s
 *      - If key exists: immediately throws IdempotencyConflictException (HTTP 409).
 *      - If key is new: key reserved for processing.
 *   2. commit():  SET idempotency:{key} "completed" EX 86400s (24 hours retention).
 *   3. release(): DEL idempotency:{key} on business errors (allows client to retry corrected payload).
 */
final class RedisIdempotencyFilter implements IdempotencyFilterInterface
{
    public const KEY_PREFIX = 'idempotency:';
    public const DEFAULT_LOCK_TTL = 60; // 60 seconds processing lock
    public const DEFAULT_RETENTION_TTL = 86400; // 24 hours finalized retention

    private ?\Redis $redis = null;

    public function __construct(
        #[Autowire(env: 'REDIS_URL')]
        private readonly string $redisUrl,
        private readonly int $lockTtl = self::DEFAULT_LOCK_TTL,
        private readonly int $retentionTtl = self::DEFAULT_RETENTION_TTL,
    ) {}

    public function acquire(string $idempotencyKey): void
    {
        $key = self::KEY_PREFIX . $idempotencyKey;
        $payload = json_encode([
            'status' => 'processing',
            'started_at' => time(),
        ], JSON_THROW_ON_ERROR);

        // Atomic reservation: only succeeds if the key does not already exist
        $acquired = $this->getRedis()->set($key, $payload, [
            'NX',
            'EX' => $this->lockTtl,
        ]);

        if (!$acquired) {
            throw new IdempotencyConflictException($idempotencyKey);
        }
    }

    public function commit(string $idempotencyKey, ?int $paymentId = null): void
    {
        $key = self::KEY_PREFIX . $idempotencyKey;
        $payload = json_encode([
            'status' => 'completed',
            'payment_id' => $paymentId,
            'completed_at' => time(),
        ], JSON_THROW_ON_ERROR);

        $this->getRedis()->set($key, $payload, [
            'EX' => $this->retentionTtl,
        ]);
    }

    public function release(string $idempotencyKey): void
    {
        $key = self::KEY_PREFIX . $idempotencyKey;
        $this->getRedis()->del($key);
    }

    private function getRedis(): \Redis
    {
        if ($this->redis === null) {
            /** @var \Redis $client */
            $client = RedisAdapter::createConnection($this->redisUrl);
            $this->redis = $client;
        }

        return $this->redis;
    }
}
