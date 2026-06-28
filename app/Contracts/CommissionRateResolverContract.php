<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\CommissionOperationType;
use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Models\ValueObjects\Settings\PrimeTimeSettings;
use App\Services\Commission\Values\ResolvedCommissionRates;
use App\Services\Money\Money;

interface CommissionRateResolverContract
{
    public function resolve(
        PaymentGateway $paymentGateway,
        Money $amount,
        CommissionOperationType $operationType,
        ?Merchant $merchant = null,
        ?User $trader = null,
        ?PrimeTimeSettings $primeTime = null,
    ): ResolvedCommissionRates;
}
