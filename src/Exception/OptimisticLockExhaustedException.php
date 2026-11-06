<?php

declare(strict_types=1);

namespace App\Exception;

final class OptimisticLockExhaustedException extends \RuntimeException
{
    public function __construct(int $orderId, int $maxRetries)
    {
        parent::__construct(sprintf(
            'Payment failed after %d retries due to concurrent modifications on order #%d.',
            $maxRetries,
            $orderId,
        ));
    }
}
