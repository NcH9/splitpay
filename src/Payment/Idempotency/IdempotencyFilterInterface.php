<?php

declare(strict_types=1);

namespace App\Payment\Idempotency;

use App\Exception\IdempotencyConflictException;

/**
 * Fast idempotency pre-filter interface (Stripe idempotency pattern).
 *
 * Protects PostgreSQL by intercepting and rejecting duplicate payment requests
 * in-memory before database connections, transactions, and entity queries occur.
 */
interface IdempotencyFilterInterface
{
    /**
     * Atomically reserves an in-flight idempotency key in Redis.
     *
     * @throws IdempotencyConflictException if the key is already in-flight or completed.
     */
    public function acquire(string $idempotencyKey): void;

    /**
     * Finalizes the key with long-term retention upon successful transaction commit.
     */
    public function commit(string $idempotencyKey, ?int $paymentId = null): void;

    /**
     * Releases the key reservation when a request fails with a business or recoverable error.
     */
    public function release(string $idempotencyKey): void;
}
