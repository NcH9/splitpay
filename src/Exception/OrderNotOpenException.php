<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\OrderStatus;

/**
 * Thrown when a payment is attempted on an order that is not in Open status.
 * Maps to: HTTP 409 Conflict.
 */
final class OrderNotOpenException extends \RuntimeException
{
    public function __construct(int $orderId, OrderStatus $status)
    {
        parent::__construct(sprintf(
            'Order #%d cannot accept payments: its status is "%s".',
            $orderId,
            $status->value,
        ));
    }
}
