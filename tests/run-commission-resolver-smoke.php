#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Enums\CommissionOperationType;
use App\Models\Merchant;
use App\Models\PaymentGateway;
use App\Models\PaymentGatewayCommissionTier;
use App\Models\TraderCommissionRate;
use App\Models\User;
use App\Models\ValueObjects\Settings\PrimeTimeSettings;
use App\Services\Commission\CommissionRateResolver;
use App\Services\Commission\CommissionTierService;
use App\Services\Commission\Exceptions\CommissionTierException;
use App\Services\Commission\TraderCommissionRateService;
use App\Services\Money\Money;
use Illuminate\Support\Carbon;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\n  expected: ".var_export($expected, true)."\n  actual:   ".var_export($actual, true)."\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

Carbon::setTestNow(Carbon::parse('2026-06-28 14:00:00'));

$resolver = new CommissionRateResolver();

$gateway = new PaymentGateway([
    'trader_commission_rate_for_orders' => 6,
    'total_service_commission_rate_for_orders' => 10,
    'trader_commission_rate_for_payouts' => 2,
    'total_service_commission_rate_for_payouts' => 3,
]);
$gateway->id = 1;
$gateway->setRelation('commissionTiers', collect([
    new PaymentGatewayCommissionTier([
        'payment_gateway_id' => 1,
        'operation_type' => CommissionOperationType::ORDER,
        'min_amount' => 5001,
        'max_amount' => 50000,
        'trader_commission_rate' => 6,
        'total_service_commission_rate' => 10,
        'sort_order' => 5001,
    ]),
]));

$amount = Money::fromPrecision('15000', 'RUB');

$defaults = $resolver->resolve($gateway, $amount, CommissionOperationType::ORDER);
assertSameValue(10.0, $defaults->totalServiceCommissionRate, 'gateway default total rate');
assertSameValue(6.0, $defaults->traderCommissionRate, 'gateway default trader rate');

$merchant = new Merchant([
    'gateway_settings' => [
        1 => ['custom_gateway_commission' => 9],
    ],
]);
$merchant->id = 1;

$merchantOverride = $resolver->resolve($gateway, $amount, CommissionOperationType::ORDER, $merchant);
assertSameValue(9.0, $merchantOverride->totalServiceCommissionRate, 'merchant flat override total rate');
assertSameValue(6.0, $merchantOverride->traderCommissionRate, 'merchant flat override trader rate');

$trader = new User();
$trader->id = 42;
$trader->setRelation('traderCommissionRates', collect([
    new TraderCommissionRate([
        'user_id' => 42,
        'payment_gateway_id' => 1,
        'operation_type' => CommissionOperationType::ORDER,
        'min_amount' => null,
        'max_amount' => null,
        'trader_commission_rate' => 7.5,
        'is_active' => true,
    ]),
]));

$traderOverride = $resolver->resolve($gateway, $amount, CommissionOperationType::ORDER, null, $trader);
assertSameValue(10.0, $traderOverride->totalServiceCommissionRate, 'trader flat override total rate');
assertSameValue(7.5, $traderOverride->traderCommissionRate, 'trader flat override trader rate');

$primeTime = new PrimeTimeSettings(starts: '13:00:00', ends: '15:00:00', rate: 0.5);
$withPrimeTime = $resolver->resolve($gateway, $amount, CommissionOperationType::ORDER, null, null, $primeTime);
assertSameValue(6.5, $withPrimeTime->traderCommissionRate, 'prime time bonus applied to trader rate');

$payout = $resolver->resolve($gateway, $amount, CommissionOperationType::PAYOUT);
assertSameValue(3.0, $payout->totalServiceCommissionRate, 'payout total rate');
assertSameValue(2.0, $payout->traderCommissionRate, 'payout trader rate');

Carbon::setTestNow();

$tierService = new CommissionTierService();
$tierService->assertTiersAreValid([
    ['min_amount' => 1000, 'max_amount' => 5000, 'trader_commission_rate' => 6, 'total_service_commission_rate' => 10],
    ['min_amount' => 5001, 'max_amount' => 50000, 'trader_commission_rate' => 5, 'total_service_commission_rate' => 9],
]);
echo "PASS: commission tier validation accepts non-overlapping tiers\n";

try {
    $tierService->assertTiersAreValid([
        ['min_amount' => 1000, 'max_amount' => 5000, 'trader_commission_rate' => 6, 'total_service_commission_rate' => 10],
        ['min_amount' => 4000, 'max_amount' => 50000, 'trader_commission_rate' => 5, 'total_service_commission_rate' => 9],
    ]);
    fwrite(STDERR, "FAIL: overlapping tiers should throw\n");
    exit(1);
} catch (CommissionTierException) {
    echo "PASS: commission tier validation rejects overlapping tiers\n";
}

echo "\nAll commission resolver smoke tests passed.\n";
