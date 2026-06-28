<?php

declare(strict_types=1);

namespace App\Services\Order\Features\OrderDetailProvider\Classes\Utils;

use App\Enums\CommissionOperationType;
use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Models\ValueObjects\Settings\PrimeTimeSettings;
use App\Services\Money\Money;
use App\Services\Order\Features\OrderDetailProvider\Values\Gateway;

class GatewayFactory
{
    public function __construct(
        protected Merchant $merchant,
        protected ?PrimeTimeSettings $primeTime = null,
    ) {
        $this->primeTime ??= services()->settings()->getPrimeTimeBonus();
    }

    public function make(PaymentGateway $paymentGateway, Money $amount, ?User $trader = null): Gateway
    {
        $customGatewaySettings = $this->merchant->gateway_settings[$paymentGateway->id] ?? null;

        $resolvedRates = services()->commissionRate()->resolve(
            paymentGateway: $paymentGateway,
            amount: $amount,
            operationType: CommissionOperationType::ORDER,
            merchant: $this->merchant,
            trader: $trader,
            primeTime: $this->primeTime,
        );

        if (! empty($customGatewaySettings['custom_gateway_reservation_time'])) {
            $reservationTime = (int) $customGatewaySettings['custom_gateway_reservation_time'];
        } else {
            $reservationTime = $paymentGateway->reservation_time_for_orders;
        }

        return new Gateway(
            id: $paymentGateway->id,
            code: $paymentGateway->code,
            reservationTime: $reservationTime,
            serviceCommissionRate: $resolvedRates->totalServiceCommissionRate,
            traderCommissionRate: $resolvedRates->traderCommissionRate,
        );
    }
}
