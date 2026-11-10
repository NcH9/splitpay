<?php

declare(strict_types=1);

namespace App\DTO\Payment;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for POST /payments/{orderId}.
 * Bound from the request body via #[MapRequestPayload] in PaymentController.
 */
final readonly class ProcessPaymentRequest
{
    public function __construct(
        /**
         * Payment amount in integer minor units (e.g. $10.50 → 1050).
         */
        #[Assert\NotNull]
        #[Assert\Positive]
        public int $amountCents,

        /**
         * Payment method: 'card' or 'cash'.
         */
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['card', 'cash'])]
        public string $paymentMethod,

        /**
         * Client-supplied idempotency key — prevents duplicate submissions.
         * The caller is responsible for generating a unique value per attempt.
         */
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $idempotencyKey,

        /**
         * ID of the User making this payment.
         */
        #[Assert\NotNull]
        #[Assert\Positive]
        public int $userId,

        /**
         * Synchronization strategy to use for this payment.
         * Defaults to 'baseline' (no lock — exposes race conditions).
         */
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['baseline', 'pessimistic', 'optimistic', 'mutex', 'atomic'])]
        public string $strategy = 'baseline',
    ) {}
}
