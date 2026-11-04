<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Thrown when a payment with the same idempotency_key already exists.
 * Maps to: HTTP 409 Conflict.
 * Invariant violated: I5.
 */
final class IdempotencyConflictException extends \RuntimeException
{
    public function __construct(string $idempotencyKey)
    {
        parent::__construct(sprintf(
            'A payment with idempotency key "%s" already exists.',
            $idempotencyKey,
        ));
    }
}
