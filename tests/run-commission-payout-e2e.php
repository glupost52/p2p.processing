#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\DTO\Payout\PayoutCreateDTO;
use App\Enums\BalanceType;
use App\Enums\CommissionOperationType;
use App\Enums\MarketEnum;
use App\Enums\PayoutMethodType;
use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\Payout\Payout;
use App\Models\TraderCommissionRate;
use App\Models\User;
use App\Enums\PayoutStatus;
use App\Services\Commission\CommissionRateResolver;
use App\Services\Market\Utils\MarketStore;
use App\Services\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

config(['queue.default' => 'sync', 'cache.default' => 'array']);
Event::fake([
    App\Events\DetailsAssignedToOrderEvent::class,
    App\Events\OrderFinishedAsFailedEvent::class,
]);
Queue::fake();
Carbon::setTestNow(Carbon::parse('2026-06-28 12:00:00'));

$passed = 0;
$failed = 0;
$createdPayoutIds = [];
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

function assertFloatSame(float $expected, float $actual, string $message): void
{
    if (abs($expected - $actual) < 0.0001) {
        pass($message);
    } else {
        fail($message, $expected, $actual);
    }
}

echo "\n=== Commission Payout E2E ===\n";

MarketStore::putPrice(
    currency: \App\Services\Money\Currency::make('rub'),
    market: MarketEnum::RAPIRA,
    buy_price: Money::fromPrecision('100', 'rub')->toUnits(),
    sell_price: Money::fromPrecision('100', 'rub')->toUnits(),
);

$merchant = Merchant::query()->with('user.wallet')->first();
$trader = User::role('Trader')->first();
$gateway = PaymentGateway::query()->with('commissionTiers')->find($gatewayId);

if ($merchant === null || $trader === null || $gateway === null) {
    echo "FAIL: missing merchant, trader or gateway\n";
    exit(1);
}

if (! $merchant->user?->wallet) {
    services()->wallet()->create($merchant->user);
    $merchant->load('user.wallet');
}

services()->wallet()->giveToBalance(
    $merchant->user->wallet->id,
    Money::fromPrecision('100000', 'usdt'),
    \App\Enums\TransactionType::DEPOSIT_BY_ADMIN,
    BalanceType::MERCHANT,
);
$merchant->user->wallet->refresh();
$availableAfterFund = services()->wallet()->getTotalAvailableBalance($merchant->user->wallet, BalanceType::MERCHANT);
pass('Setup: merchant MERCHANT balance funded ('.$availableAfterFund->toBeauty().' USDT)');

$staleActivePayouts = Payout::query()
    ->where('trader_id', $trader->id)
    ->whereIn('status', [PayoutStatus::TAKEN->value, PayoutStatus::SENT->value])
    ->get();

foreach ($staleActivePayouts as $stalePayout) {
    try {
        services()->payout()->adminChangeStatus($stalePayout, PayoutStatus::CANCELED, note: 'e2e cleanup');
    } catch (Throwable $exception) {
        fail('Setup: cancel stale active payout #'.$stalePayout->id, 'success', $exception->getMessage());
    }
}

if ($staleActivePayouts->isNotEmpty()) {
    pass('Setup: cleared '.$staleActivePayouts->count().' stale active payout(s) for trader');
}

$resolver = new CommissionRateResolver();
$amount15k = Money::fromPrecision('15000', 'rub');

$createPayout = function (string $label, float $expectedTotal, float $expectedTrader) use (
    &$createdPayoutIds,
    $merchant,
    $gateway,
    $amount15k,
    $resolver,
): ?Payout {
    $expected = $resolver->resolve(
        paymentGateway: $gateway,
        amount: $amount15k,
        operationType: CommissionOperationType::PAYOUT,
        merchant: $merchant,
    );

    if (abs($expected->totalServiceCommissionRate - $expectedTotal) >= 0.0001
        || abs($expected->traderCommissionRate - $expectedTrader) >= 0.0001) {
        fail("{$label}: resolver pre-check", "{$expectedTotal}/{$expectedTrader}", "{$expected->totalServiceCommissionRate}/{$expected->traderCommissionRate}");

        return null;
    }

    try {
        $payout = services()->payout()->create(PayoutCreateDTO::make(
            merchant: $merchant,
            paymentGateway: $gateway,
            externalId: 'e2e-payout-'.uniqid(),
            amountFiat: $amount15k,
            methodType: PayoutMethodType::CARD,
            requisites: '2202200220999999',
            initials: 'Test User',
            currencyCode: 'rub',
            callbackUrl: null,
            bankName: $gateway->name,
        ));
    } catch (Throwable $exception) {
        fail("{$label}: payout create", 'success', $exception->getMessage());

        return null;
    }

    $createdPayoutIds[] = $payout->id;
    $payout->refresh();

    assertFloatSame($expectedTotal, (float) $payout->total_commission_rate, "{$label}: payout total rate");
    assertFloatSame($expectedTrader, (float) $payout->trader_commission_rate, "{$label}: payout trader rate on create");

    $profits = services()->profit()->calculateOutBody(
        sourceAmount: $payout->amount_fiat,
        exchangeRate: $payout->conversion_price,
        totalFeeRate: (float) $payout->total_commission_rate,
        traderFeeRate: (float) $payout->trader_commission_rate,
        teamLeaderFeeRate: 0,
        teamLeaderServiceSplitPercent: null,
    );

    if ($payout->total_fee->equals($profits->totalFee) && $payout->trader_fee->equals($profits->traderFee)) {
        pass("{$label}: payout profit fields match calculator");
    } else {
        fail("{$label}: payout profit fields mismatch");
    }

    return $payout;
};

echo "\n--- Scenarios ---\n";

$payoutA = $createPayout('PAYOUT-A inherit / gateway tier', 3.5, 2.5);
if ($payoutA !== null) {
    try {
        services()->payout()->cancel($payoutA);
        pass('PAYOUT-A: canceled open payout');
    } catch (Throwable $exception) {
        fail('PAYOUT-A: cancel payout', 'success', $exception->getMessage());
    }
}

$traderRateBackup = TraderCommissionRate::query()
    ->where('user_id', $trader->id)
    ->where('payment_gateway_id', $gatewayId)
    ->where('operation_type', CommissionOperationType::PAYOUT)
    ->get()
    ->all();

TraderCommissionRate::query()
    ->where('user_id', $trader->id)
    ->where('payment_gateway_id', $gatewayId)
    ->where('operation_type', CommissionOperationType::PAYOUT)
    ->delete();

TraderCommissionRate::query()->create([
    'user_id' => $trader->id,
    'payment_gateway_id' => $gatewayId,
    'operation_type' => CommissionOperationType::PAYOUT,
    'min_amount' => null,
    'max_amount' => null,
    'trader_commission_rate' => 1.5,
    'is_active' => true,
]);

$payoutB = $createPayout('PAYOUT-B create with gateway tier total', 3.5, 2.5);
if ($payoutB !== null) {
    try {
        $taken = services()->payout()->take($payoutB, $trader);
        assertFloatSame(3.5, (float) $taken->total_commission_rate, 'PAYOUT-B: total unchanged after take');
        assertFloatSame(1.5, (float) $taken->trader_commission_rate, 'PAYOUT-B: trader override after take');

        $profitsAfterTake = services()->profit()->calculateOutBody(
            sourceAmount: $taken->amount_fiat,
            exchangeRate: $taken->conversion_price,
            totalFeeRate: (float) $taken->total_commission_rate,
            traderFeeRate: (float) $taken->trader_commission_rate,
            teamLeaderFeeRate: 0,
            teamLeaderServiceSplitPercent: null,
        );

        if ($taken->trader_fee->equals($profitsAfterTake->traderFee)) {
            pass('PAYOUT-B: trader fee recalculated after take');
        } else {
            fail('PAYOUT-B: trader fee after take mismatch');
        }
    } catch (Throwable $exception) {
        fail('PAYOUT-B: take payout', 'success', $exception->getMessage());
    }
}

echo "\n--- Cleanup ---\n";

TraderCommissionRate::query()
    ->where('user_id', $trader->id)
    ->where('payment_gateway_id', $gatewayId)
    ->where('operation_type', CommissionOperationType::PAYOUT)
    ->delete();

foreach ($traderRateBackup as $rate) {
    TraderCommissionRate::query()->create($rate->only([
        'user_id', 'payment_gateway_id', 'operation_type',
        'min_amount', 'max_amount', 'trader_commission_rate', 'is_active',
    ]));
}

pass('Cleanup: restored trader payout rates');

Carbon::setTestNow();

echo "\n========================================\n";
echo "PASSED: {$passed}\n";
echo "FAILED: {$failed}\n";
echo "PAYOUTS TESTED: ".count($createdPayoutIds)."\n";
echo "========================================\n";

exit($failed > 0 ? 1 : 0);
