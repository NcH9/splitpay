<?php

declare(strict_types=1);

namespace App\DTO\Order;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateOrderRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 2, max: 255)]
        public readonly string $name,

        /**
         * Full order price in integer minor units (e.g. $10.50 → 1050).
         */
        #[Assert\NotNull]
        #[Assert\Positive]
        public readonly int $fullPriceCents,

        /**
         * ID of the User who creates/owns this order.
         */
        #[Assert\NotNull]
        #[Assert\Positive]
        public readonly int $userId,
    ) {}
}
