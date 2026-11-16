<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Domain event dispatched asynchronously via RabbitMQ when a payment is committed.
 */
final readonly class PaymentReceivedEvent
{
    public function __construct(
        public int $paymentId,
        public int $orderId,
        public int $userId,
        public int $amountCents,
        public string $paymentMethod,
        public string $idempotencyKey,
        public string $occurredAt,
    ) {}
}
