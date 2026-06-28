<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Money\Currency;
use App\Services\Money\Money;
use App\Services\Order\Features\OrderDetailProvider\Values\Detail;
use App\Services\Order\Features\OrderDetailProvider\Values\Gateway;
use App\Services\Order\Features\OrderDetailProvider\Values\Trader;
use PHPUnit\Framework\TestCase;

class OrderDetailDetailTest extends TestCase
{
    public function test_accepts_null_daily_limit(): void
    {
        $currency = Currency::TRY();
        $zero = Money::fromPrecision(0, $currency);
        $amount = Money::fromPrecision(1231, $currency);

        $detail = new Detail(
            id: 1,
            userID: 2,
            paymentGatewayID: 3,
            userDeviceID: null,
            dailyLimit: null,
            currentDailyLimit: $zero,
            currency: $currency,
            exchangePrice: Money::fromPrecision(35, Currency::USDT()),
            totalProfit: Money::fromPrecision(35, Currency::USDT()),
            serviceProfit: Money::fromPrecision(1, Currency::USDT()),
            merchantProfit: Money::fromPrecision(34, Currency::USDT()),
            traderProfit: Money::fromPrecision(1, Currency::USDT()),
            teamLeaderProfit: Money::fromPrecision(0, Currency::USDT()),
            traderCommissionRate: 1.0,
            teamLeaderCommissionRate: 0.0,
            traderPaidForOrder: Money::fromPrecision(35, Currency::USDT()),
            gateway: new Gateway(
                id: 3,
                code: 'test',
                reservationTime: 15,
                serviceCommissionRate: 2.0,
                traderCommissionRate: 1.0,
            ),
            trader: new Trader(
                id: 2,
                trustBalance: Money::fromPrecision(1000, Currency::USDT()),
                teamLeaderID: null,
                teamLeaderCommissionRate: 0.0,
                teamLeaderSplitFromServicePercent: 0.0,
            ),
            amount: $amount,
        );

        $this->assertNull($detail->dailyLimit);
        $this->assertSame($amount, $detail->amount);
    }
}
