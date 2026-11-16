<?php

declare(strict_types=1);

namespace App\Enum;

enum OrderStatus: string
{
    case Open      = 'open';
    case Paid      = 'paid';
    case Cancelled = 'cancelled';
}
