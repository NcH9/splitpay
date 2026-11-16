<?php

declare(strict_types=1);

namespace App\Benchmark;

final readonly class PaymentIntent
{
    public function __construct(
        public int $userId,
        public int $amountCents,
        public string $paymentMethod = 'card',
        public ?string $idempotencyKey = null,
        public ?string $participantLabel = null,
    ) {
        if ($this->userId < 1) {
            throw new \InvalidArgumentException('User ID must be a positive integer.');
        }
        if ($this->amountCents < 1) {
            throw new \InvalidArgumentException('Payment amount must be a positive integer.');
        }
        if (!in_array($this->paymentMethod, ['card', 'cash'], true)) {
            throw new \InvalidArgumentException('Payment method must be "card" or "cash".');
        }
    }
}
