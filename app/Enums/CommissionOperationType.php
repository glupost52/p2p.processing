<?php

declare(strict_types=1);

namespace App\Enums;

use App\Traits\Enumable;

enum CommissionOperationType: string
{
    use Enumable;

    case ORDER = 'order';
    case PAYOUT = 'payout';
}
