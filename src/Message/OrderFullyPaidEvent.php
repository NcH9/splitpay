<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Domain event dispatched asynchronously via RabbitMQ when an order is completely paid.
 */
final readonly class OrderFullyPaidEvent
{
    public function __construct(
        public int $orderId,
        public int $userId,
        public int $totalPaidCents,
        public string $occurredAt,
    ) {}
}
