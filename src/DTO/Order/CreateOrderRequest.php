<?php

namespace App\DTO\Order;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateOrderRequest
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Length(min: 3, max: 255)]
        public readonly string $username,
    ) {}
}