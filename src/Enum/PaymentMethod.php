<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentMethod: string
{
    case Card = 'card';
    case Cash = 'cash';
}
