#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\DTO\Order\CreateOrderDTO;
use App\DTO\PaymentDetail\PaymentDetailCreateDTO;
use App\Enums\BalanceType;
use App\Enums\CommissionOperationType;
use App\Enums\DetailType;
use App\Enums\MarketEnum;
use App\Enums\OrderSubStatus;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\PaymentDetail;
use App\Models\PaymentGateway;
use App\Models\TraderCommissionRate;
use App\Models\User;
use App\Services\Commission\CommissionRateResolver;
use App\Services\Market\Utils\MarketStore;
use App\Services\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

config(['queue.default' => 'sync', 'cache.default' => 'array']);
Event::fake([
    App\Events\DetailsAssignedToOrderEvent::class,
    App\Events\OrderFinishedAsFailedEvent::class,
]);
Carbon::setTestNow(Carbon::parse('2026-06-28 12:00:00'));

MarketStore::putPrice(
    currency: \App\Services\Money\Currency::make('rub'),
    market: MarketEnum::RAPIRA,
    buy_price: Money::fromPrecision('100', 'rub')->toUnits(),
    sell_price: Money::fromPrecision('100', 'rub')->toUnits(),
);

$passed = 0;
$failed = 0;
$createdOrderIds = [];
$gatewayId = 1;

function pass(string $message): void
{
    global $passed;
    $passed++;
    echo "PASS: {$message}\n";
}

function fail(string $message, mixed $expected = null, mixed $actual = null): void
{
    global $failed;
    $failed++;
    echo "FAIL: {$message}\n";
    if ($expected !== null || $actual !== null) {
        echo '  expected: '.var_export($expected, true)."\n";
        echo '  actual:   '.var_export($actual, true)."\n";
    }
}

function assertRates(Order $order, float $expectedTotal, float $expectedTrader, string $label): void
{
    $actualTotal = (float) $order->total_service_commission_rate;
    $actualTrader = (float) $order->trader_commission_rate;

    if (abs($actualTotal - $expectedTotal) < 0.0001 && abs($actualTrader - $expectedTrader) < 0.0001) {
        pass("{$label}: order #{$order->id} rates {$actualTotal}% / {$actualTrader}%");
    } else {
        fail("{$label}: order #{$order->id} commission rates", "{$expectedTotal}% / {$expectedTrader}%", "{$actualTotal}% / {$actualTrader}%");
    }
}

function assertProfitMatchesOrder(Order $order, string $label): void
{
    $profits = services()->profit()->calculateInBody(
        sourceAmount: $order->amount,
        exchangeRate: $order->conversion_price,
        totalFeeRate: (float) $order->total_service_commission_rate,
        traderFeeRate: (float) $order->trader_commission_rate,
        teamLeaderFeeRate: (float) $order->team_leader_commission_rate,
        teamLeaderServiceSplitPercent: $order->team_leader_split_from_service_percent,
    );

    if ($order->total_fee->equals($profits->totalFee)
        && $order->trader_profit->equals($profits->traderFee)
        && $order->service_profit->equals($profits->serviceFee)) {
        pass("{$label}: order #{$order->id} profit fields match calculator");
    } else {
        fail("{$label}: order #{$order->id} profit fields mismatch", 'calculator match', [
            'order_total_fee' => $order->total_fee->toPrecision(),
            'calc_total_fee' => $profits->totalFee->toPrecision(),
        ]);
    }
}

echo "\n=== Commission Order E2E ===\n";

$merchant = Merchant::query()->first();
$trader = User::role('Trader')->first();
$gateway = PaymentGateway::query()->with('commissionTiers')->find($gatewayId);

if ($merchant === null || $trader === null || $gateway === null) {
    echo "FAIL: missing merchant, trader or gateway\n";
    exit(1);
}

$validInheritSettings = [
    $gatewayId => [
        'active' => true,
        'commission_mode' => 'inherit',
    ],
];

$merchant->update(['gateway_settings' => $validInheritSettings]);
forgetMerchantCache($merchant);
pass('Setup: merchant gateway #1 set to inherit mode');

if (! $trader->wallet) {
    services()->wallet()->create($trader);
    $trader->refresh();
}

services()->wallet()->giveToBalance(
    $trader->wallet->id,
    Money::fromPrecision('50000', 'usdt'),
    \App\Enums\TransactionType::DEPOSIT_BY_ADMIN,
    BalanceType::TRUST,
);
pass('Setup: trader wallet funded with 50000 USDT');

$paymentDetail = PaymentDetail::query()
    ->where('user_id', $trader->id)
    ->whereNull('archived_at')
    ->whereHas('paymentGateways', fn ($query) => $query->where('payment_gateways.id', $gatewayId))
    ->first();

if ($paymentDetail === null) {
    $cardNumber = '2202200220123456';
    while (PaymentDetail::query()->where('detail', $cardNumber)->exists()) {
        $cardNumber = '2202200220'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    $paymentDetail = services()->paymentDetail()->create(new PaymentDetailCreateDTO(
        name: 'E2E Commission Test',
        detail: $cardNumber,
        detail_type: DetailType::CARD,
        initials: 'Test Trader',
        is_active: true,
        daily_limit: 5000000,
        daily_successful_orders_limit: null,
        currency: 'rub',
        payment_gateway_ids: [$gatewayId],
        max_pending_orders_quantity: 100,
        order_interval_minutes: null,
        user_device_id: null,
        user_id: $trader->id,
        min_order_amount: 1000,
        max_order_amount: 500000,
    ));
    pass('Setup: created payment detail #'.$paymentDetail->id.' for gateway #'.$gatewayId);
} else {
    pass('Setup: using existing payment detail #'.$paymentDetail->id);
}

$resolver = new CommissionRateResolver();

function forgetMerchantCache(Merchant $merchant): void
{
    queries()->merchant()->forget($merchant);
}

function cancelOrderAfterScenario(Order $order, string $label): void
{
    try {
        Order::withoutEvents(function () use ($order): void {
            $order->update([
                'status' => \App\Enums\OrderStatus::FAIL,
                'sub_status' => OrderSubStatus::CANCELED,
                'finished_at' => now(),
            ]);
        });
        pass("{$label}: canceled order #{$order->id} for next scenario");
    } catch (Throwable $exception) {
        fail("{$label}: cancel order #{$order->id}", 'success', $exception->getMessage());
    }
}

$runScenario = function (
    string $label,
    string $amount,
    float $expectedTotal,
    float $expectedTrader,
    ?callable $beforeCreate = null,
) use (
    $merchant,
    $gateway,
    $trader,
    $resolver,
    &$createdOrderIds,
): void {
    if ($beforeCreate !== null) {
        $beforeCreate();
        $merchant->refresh();
        $gateway->refresh();
        $gateway->load('commissionTiers');
        $trader->load('traderCommissionRates');
    }

    $amountMoney = Money::fromPrecision($amount, 'RUB');

    $expectedFromResolver = $resolver->resolve(
        paymentGateway: $gateway,
        amount: $amountMoney,
        operationType: CommissionOperationType::ORDER,
        merchant: $merchant,
        trader: $trader,
    );

    if (abs($expectedFromResolver->totalServiceCommissionRate - $expectedTotal) >= 0.0001
        || abs($expectedFromResolver->traderCommissionRate - $expectedTrader) >= 0.0001) {
        fail(
            "{$label}: resolver pre-check",
            "{$expectedTotal}% / {$expectedTrader}%",
            "{$expectedFromResolver->totalServiceCommissionRate}% / {$expectedFromResolver->traderCommissionRate}%",
        );

        return;
    }

    try {
        $order = services()->order()->create(new CreateOrderDTO(
            amount: $amountMoney,
            merchant: $merchant,
            paymentGateway: $gateway,
            paymentDetailType: DetailType::CARD,
            externalID: 'e2e-commission-'.uniqid(),
        ));
    } catch (Throwable $exception) {
        fail("{$label}: order creation", 'success', $exception->getMessage());

        return;
    }

    $createdOrderIds[] = $order->id;
    $order->refresh();

    assertRates($order, $expectedTotal, $expectedTrader, $label);
    assertProfitMatchesOrder($order, $label);
    cancelOrderAfterScenario($order, $label);
};

echo "\n--- Scenarios ---\n";

$runScenario('E2E-A inherit / gateway tier high', '15000', 10.0, 6.0);
$runScenario('E2E-B inherit / gateway tier low', '3000', 12.0, 8.0);

$runScenario(
    'E2E-C merchant flat 9%',
    '15000',
    9.0,
    6.0,
    function () use ($merchant, $gatewayId): void {
        $settings = $merchant->gateway_settings ?? [];
        $settings[$gatewayId] = [
            'active' => true,
            'commission_mode' => 'flat',
            'custom_gateway_commission' => 9,
        ];
        $merchant->update(['gateway_settings' => $settings]);
        forgetMerchantCache($merchant);
        $merchant->refresh();
    },
);

$runScenario(
    'E2E-D merchant tiered total only',
    '15000',
    11.0,
    6.0,
    function () use ($merchant, $gatewayId): void {
        $settings = $merchant->gateway_settings ?? [];
        $settings[$gatewayId] = [
            'active' => true,
            'commission_mode' => 'tiered',
            'custom_gateway_commission_tiers' => [
                [
                    'min_amount' => 1000,
                    'max_amount' => 20000,
                    'total_service_commission_rate' => 11,
                ],
                [
                    'min_amount' => 20001,
                    'max_amount' => 50000,
                    'total_service_commission_rate' => 9,
                ],
            ],
        ];
        $merchant->update(['gateway_settings' => $settings]);
        forgetMerchantCache($merchant);
        $merchant->refresh();
    },
);

$traderRateBackup = TraderCommissionRate::query()
    ->where('user_id', $trader->id)
    ->where('payment_gateway_id', $gatewayId)
    ->where('operation_type', CommissionOperationType::ORDER)
    ->get()
    ->all();

TraderCommissionRate::query()
    ->where('user_id', $trader->id)
    ->where('payment_gateway_id', $gatewayId)
    ->where('operation_type', CommissionOperationType::ORDER)
    ->delete();

TraderCommissionRate::query()->create([
    'user_id' => $trader->id,
    'payment_gateway_id' => $gatewayId,
    'operation_type' => CommissionOperationType::ORDER,
    'min_amount' => null,
    'max_amount' => null,
    'trader_commission_rate' => 7.5,
    'is_active' => true,
]);

$runScenario(
    'E2E-E trader flat override 7.5%',
    '15000',
    10.0,
    7.5,
    function () use ($merchant, $gatewayId): void {
        $settings = $merchant->gateway_settings ?? [];
        $settings[$gatewayId] = [
            'active' => true,
            'commission_mode' => 'inherit',
        ];
        $merchant->update(['gateway_settings' => $settings]);
        forgetMerchantCache($merchant);
        $merchant->refresh();
    },
);

echo "\n--- Cleanup ---\n";

TraderCommissionRate::query()
    ->where('user_id', $trader->id)
    ->where('payment_gateway_id', $gatewayId)
    ->where('operation_type', CommissionOperationType::ORDER)
    ->delete();

foreach ($traderRateBackup as $rate) {
    TraderCommissionRate::query()->create($rate->only([
        'user_id',
        'payment_gateway_id',
        'operation_type',
        'min_amount',
        'max_amount',
        'trader_commission_rate',
        'is_active',
    ]));
}

$validInheritSettings = [
    $gatewayId => [
        'active' => true,
        'commission_mode' => 'inherit',
    ],
];

$merchant->update(['gateway_settings' => $validInheritSettings]);
forgetMerchantCache($merchant);
pass('Cleanup: restored merchant gateway_settings to inherit mode');

Carbon::setTestNow();

echo "\n========================================\n";
echo "PASSED: {$passed}\n";
echo "FAILED: {$failed}\n";
echo "ORDERS TESTED: ".count($createdOrderIds)."\n";
echo "========================================\n";

exit($failed > 0 ? 1 : 0);
