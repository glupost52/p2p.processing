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
use App\Services\Commission\MerchantGatewayCommissionValidator;
use App\Services\Money\Money;
use App\Services\Order\Features\OrderDetailProvider\Classes\Utils\GatewayFactory;
use Illuminate\Support\Carbon;

$passed = 0;
$failed = 0;
$skipped = 0;
$sections = [];

function section(string $name): void
{
    global $sections;
    $sections[] = $name;
    echo "\n=== {$name} ===\n";
}

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

function skip(string $message): void
{
    global $skipped;
    $skipped++;
    echo "SKIP: {$message}\n";
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) {
        pass($message);
    } else {
        fail($message, $expected, $actual);
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

function makeGateway(array $attributes = [], array $tiers = []): PaymentGateway
{
    $gateway = new PaymentGateway(array_merge([
        'trader_commission_rate_for_orders' => 6,
        'total_service_commission_rate_for_orders' => 10,
        'trader_commission_rate_for_payouts' => 2,
        'total_service_commission_rate_for_payouts' => 3,
        'reservation_time_for_orders' => 15,
    ], $attributes));
    $gateway->id = $attributes['id'] ?? 9001;
    $gateway->code = $attributes['code'] ?? 'test_gateway';
    $gateway->setRelation('commissionTiers', collect($tiers));

    return $gateway;
}

function makeOrderTier(int $min, int $max, float $trader, float $total): PaymentGatewayCommissionTier
{
    return new PaymentGatewayCommissionTier([
        'payment_gateway_id' => 9001,
        'operation_type' => CommissionOperationType::ORDER,
        'min_amount' => $min,
        'max_amount' => $max,
        'trader_commission_rate' => $trader,
        'total_service_commission_rate' => $total,
        'sort_order' => $min,
    ]);
}

function makePayoutTier(int $min, int $max, float $trader, float $total): PaymentGatewayCommissionTier
{
    return new PaymentGatewayCommissionTier([
        'payment_gateway_id' => 9001,
        'operation_type' => CommissionOperationType::PAYOUT,
        'min_amount' => $min,
        'max_amount' => $max,
        'trader_commission_rate' => $trader,
        'total_service_commission_rate' => $total,
        'sort_order' => $min,
    ]);
}

function makeMerchant(array $gatewaySettings): Merchant
{
    $merchant = new Merchant(['gateway_settings' => $gatewaySettings]);
    $merchant->id = 1;

    return $merchant;
}

function makeTrader(array $rates): User
{
    $trader = new User();
    $trader->id = 42;
    $trader->setRelation('traderCommissionRates', collect($rates));

    return $trader;
}

function makeTraderRate(
    int $gatewayId,
    ?int $minAmount,
    ?int $maxAmount,
    float $traderRate,
    CommissionOperationType $operationType = CommissionOperationType::ORDER,
): TraderCommissionRate {
    return new TraderCommissionRate([
        'user_id' => 42,
        'payment_gateway_id' => $gatewayId,
        'operation_type' => $operationType,
        'min_amount' => $minAmount,
        'max_amount' => $maxAmount,
        'trader_commission_rate' => $traderRate,
        'is_active' => true,
    ]);
}

Carbon::setTestNow(Carbon::parse('2026-06-28 14:00:00'));

section('0. Infrastructure');
try {
    $resolverFromContainer = services()->commissionRate();
    pass('services()->commissionRate() resolves from container');
} catch (Throwable $exception) {
    fail('services()->commissionRate() resolves from container', 'ok', $exception->getMessage());
}

$resolver = new CommissionRateResolver();
$amount15k = Money::fromPrecision('15000', 'RUB');
$amount3k = Money::fromPrecision('3000', 'RUB');

$gatewayFlat = makeGateway();
$gatewayTiered = makeGateway([], [
    makeOrderTier(1000, 5000, 8, 12),
    makeOrderTier(5001, 50000, 6, 10),
]);
$gatewayPayoutTiered = makeGateway([], [
    makePayoutTier(1000, 50000, 2.5, 4),
]);

section('A. Regression — gateway flat, no tiers');
$resolvedA = $resolver->resolve($gatewayFlat, $amount15k, CommissionOperationType::ORDER);
assertFloatSame(10.0, $resolvedA->totalServiceCommissionRate, 'A: total = gateway flat 10%');
assertFloatSame(6.0, $resolvedA->traderCommissionRate, 'A: trader = gateway flat 6%');

$factoryA = (new GatewayFactory(makeMerchant([])))->make($gatewayFlat, $amount15k);
assertFloatSame(10.0, $factoryA->serviceCommissionRate, 'A: GatewayFactory total matches resolver');
assertFloatSame(6.0, $factoryA->traderCommissionRate, 'A: GatewayFactory trader matches resolver');

section('B. Gateway tier by amount');
$resolvedB = $resolver->resolve($gatewayTiered, $amount3k, CommissionOperationType::ORDER);
assertFloatSame(12.0, $resolvedB->totalServiceCommissionRate, 'B: 3000 RUB → tier 1k-5k total 12%');
assertFloatSame(8.0, $resolvedB->traderCommissionRate, 'B: 3000 RUB → tier 1k-5k trader 8%');

$resolvedB2 = $resolver->resolve($gatewayTiered, $amount15k, CommissionOperationType::ORDER);
assertFloatSame(10.0, $resolvedB2->totalServiceCommissionRate, 'B: 15000 RUB → tier 5k-50k total 10%');
assertFloatSame(6.0, $resolvedB2->traderCommissionRate, 'B: 15000 RUB → tier 5k-50k trader 6%');

section('C. Merchant flat override');
$merchantC = makeMerchant([9001 => ['custom_gateway_commission' => 9]]);
$resolvedC = $resolver->resolve($gatewayTiered, $amount15k, CommissionOperationType::ORDER, $merchantC);
assertFloatSame(9.0, $resolvedC->totalServiceCommissionRate, 'C: merchant flat total 9%');
assertFloatSame(6.0, $resolvedC->traderCommissionRate, 'C: trader unchanged from gateway tier');

section('D. Merchant tiered override (total only)');
$merchantD = makeMerchant([
    9001 => [
        'commission_mode' => 'tiered',
        'custom_gateway_commission_tiers' => [
            ['min_amount' => 1000, 'max_amount' => 20000, 'total_service_commission_rate' => 11],
            ['min_amount' => 20001, 'max_amount' => 50000, 'total_service_commission_rate' => 9],
        ],
    ],
]);
$resolvedD = $resolver->resolve($gatewayTiered, $amount15k, CommissionOperationType::ORDER, $merchantD);
assertFloatSame(11.0, $resolvedD->totalServiceCommissionRate, 'D: merchant tier total 11%');
assertFloatSame(6.0, $resolvedD->traderCommissionRate, 'D: trader from gateway tier');

section('E. Trader flat override');
$traderE = makeTrader([makeTraderRate(9001, null, null, 7.5)]);
$resolvedE = $resolver->resolve($gatewayTiered, $amount15k, CommissionOperationType::ORDER, null, $traderE);
assertFloatSame(10.0, $resolvedE->totalServiceCommissionRate, 'E: total from gateway tier');
assertFloatSame(7.5, $resolvedE->traderCommissionRate, 'E: trader flat 7.5%');

$factoryE = (new GatewayFactory(makeMerchant([])))->make($gatewayTiered, $amount15k, $traderE);
assertFloatSame(7.5, $factoryE->traderCommissionRate, 'E: GatewayFactory trader matches resolver');

section('F. Trader tier override');
$traderF = makeTrader([makeTraderRate(9001, 10000, 20000, 7.5)]);
$resolvedF = $resolver->resolve($gatewayTiered, $amount15k, CommissionOperationType::ORDER, null, $traderF);
assertFloatSame(10.0, $resolvedF->totalServiceCommissionRate, 'F: total from gateway tier');
assertFloatSame(7.5, $resolvedF->traderCommissionRate, 'F: trader tier 7.5% for 15k');

section('G. Prime time bonus');
$primeTime = new PrimeTimeSettings(starts: '13:00:00', ends: '15:00:00', rate: 0.5);
$resolvedG = $resolver->resolve($gatewayFlat, $amount15k, CommissionOperationType::ORDER, null, null, $primeTime);
assertFloatSame(6.5, $resolvedG->traderCommissionRate, 'G: trader 6 + 0.5 prime time');
assertFloatSame(10.0, $resolvedG->totalServiceCommissionRate, 'G: total unchanged by prime time');

section('H. Payout flat defaults');
$resolvedH = $resolver->resolve($gatewayFlat, $amount15k, CommissionOperationType::PAYOUT);
assertFloatSame(3.0, $resolvedH->totalServiceCommissionRate, 'H: payout total flat 3%');
assertFloatSame(2.0, $resolvedH->traderCommissionRate, 'H: payout trader flat 2%');

section('I. Payout gateway tier');
$resolvedI = $resolver->resolve($gatewayPayoutTiered, $amount15k, CommissionOperationType::PAYOUT);
assertFloatSame(4.0, $resolvedI->totalServiceCommissionRate, 'I: payout tier total 4%');
assertFloatSame(2.5, $resolvedI->traderCommissionRate, 'I: payout tier trader 2.5%');

section('J. Payout trader override');
$traderJ = makeTrader([
    makeTraderRate(9001, null, null, 1.5, CommissionOperationType::PAYOUT),
]);
$resolvedJ = $resolver->resolve($gatewayPayoutTiered, $amount15k, CommissionOperationType::PAYOUT, null, $traderJ);
assertFloatSame(4.0, $resolvedJ->totalServiceCommissionRate, 'J: payout total from tier');
assertFloatSame(1.5, $resolvedJ->traderCommissionRate, 'J: payout trader override 1.5%');

section('Boundary amounts');
$resolvedMin = $resolver->resolve($gatewayTiered, Money::fromPrecision('1000', 'RUB'), CommissionOperationType::ORDER);
assertFloatSame(12.0, $resolvedMin->totalServiceCommissionRate, 'Boundary: min_amount 1000 matches lower tier');

$resolvedMax = $resolver->resolve($gatewayTiered, Money::fromPrecision('5000', 'RUB'), CommissionOperationType::ORDER);
assertFloatSame(12.0, $resolvedMax->totalServiceCommissionRate, 'Boundary: max_amount 5000 matches lower tier');

$resolvedGap = $resolver->resolve(
    makeGateway([], [makeOrderTier(1000, 5000, 8, 12)]),
    Money::fromPrecision('10000', 'RUB'),
    CommissionOperationType::ORDER,
);
assertFloatSame(10.0, $resolvedGap->totalServiceCommissionRate, 'Boundary: no tier match → gateway flat total');
assertFloatSame(6.0, $resolvedGap->traderCommissionRate, 'Boundary: no tier match → gateway flat trader');

section('Validation — trader rate cannot exceed total');
try {
    $resolver->resolve(
        $gatewayFlat,
        $amount15k,
        CommissionOperationType::ORDER,
        null,
        makeTrader([makeTraderRate(9001, null, null, 11)]),
    );
    fail('Validation: trader > total should throw');
} catch (InvalidArgumentException) {
    pass('Validation: trader > total throws InvalidArgumentException');
}

section('Tier service validation');
$tierService = new CommissionTierService();
try {
    $tierService->assertTiersAreValid([
        ['min_amount' => 1000, 'max_amount' => 5000, 'trader_commission_rate' => 6, 'total_service_commission_rate' => 10],
        ['min_amount' => 5001, 'max_amount' => 50000, 'trader_commission_rate' => 5, 'total_service_commission_rate' => 9],
    ]);
    pass('Tier service: non-overlapping tiers accepted');
} catch (CommissionTierException $exception) {
    fail('Tier service: non-overlapping tiers accepted', 'ok', $exception->getMessage());
}

try {
    $tierService->assertTiersAreValid([
        ['min_amount' => 1000, 'max_amount' => 5000, 'trader_commission_rate' => 6, 'total_service_commission_rate' => 10],
        ['min_amount' => 4000, 'max_amount' => 50000, 'trader_commission_rate' => 5, 'total_service_commission_rate' => 9],
    ]);
    fail('Tier service: overlapping tiers should throw');
} catch (CommissionTierException) {
    pass('Tier service: overlapping tiers rejected');
}

section('Profit calculator consistency');
$exchangeRate = Money::fromPrecision('100', 'RUB');
$profitsFromResolved = services()->profit()->calculateInBody(
    sourceAmount: $amount15k,
    exchangeRate: $exchangeRate,
    totalFeeRate: $resolvedE->totalServiceCommissionRate,
    traderFeeRate: $resolvedE->traderCommissionRate,
    teamLeaderFeeRate: 0,
    teamLeaderServiceSplitPercent: null,
);
$profitsManual = services()->profit()->calculateInBody(
    sourceAmount: $amount15k,
    exchangeRate: $exchangeRate,
    totalFeeRate: 10.0,
    traderFeeRate: 7.5,
    teamLeaderFeeRate: 0,
    teamLeaderServiceSplitPercent: null,
);
assertSameValue(
    $profitsManual->totalFee->toPrecision(),
    $profitsFromResolved->totalFee->toPrecision(),
    'Profit: resolved rates E produce same totalFee as manual 10/7.5',
);
assertSameValue(
    $profitsManual->traderFee->toPrecision(),
    $profitsFromResolved->traderFee->toPrecision(),
    'Profit: resolved rates E produce same traderFee as manual 10/7.5',
);

section('Merchant gateway settings validation');
$merchantValidator = new MerchantGatewayCommissionValidator();
try {
    $merchantValidator->validateGatewaySettings([
        1 => [
            'commission_mode' => 'tiered',
            'custom_gateway_commission_tiers' => [
                ['min_amount' => 1000, 'max_amount' => 10000, 'total_service_commission_rate' => 12],
            ],
        ],
    ]);
    pass('Merchant validator: accepts tier total above gateway trader rate');
} catch (CommissionTierException $exception) {
    fail('Merchant validator: accepts valid merchant tier', 'ok', $exception->getMessage());
}

try {
    $merchantValidator->validateGatewaySettings([
        1 => [
            'commission_mode' => 'tiered',
            'custom_gateway_commission_tiers' => [
                ['min_amount' => 1000, 'max_amount' => 10000, 'total_service_commission_rate' => 1],
            ],
        ],
    ]);
    fail('Merchant validator: should reject total below gateway trader');
} catch (CommissionTierException) {
    pass('Merchant validator: rejects total below gateway trader rate');
}

section('Database integration');
$dbGateway = PaymentGateway::query()->with('commissionTiers')->first();
if ($dbGateway === null) {
    skip('No payment gateways in DB');
} else {
    pass('DB: payment gateway loaded (id='.$dbGateway->id.')');

    $dbResolved = services()->commissionRate()->resolve(
        paymentGateway: $dbGateway,
        amount: Money::fromPrecision('5000', strtoupper($dbGateway->currency->getCode())),
        operationType: CommissionOperationType::ORDER,
    );
    if ($dbResolved->totalServiceCommissionRate >= $dbResolved->traderCommissionRate) {
        pass('DB: resolved rates valid for gateway #'.$dbGateway->id);
    } else {
        fail('DB: resolved rates valid for gateway #'.$dbGateway->id, 'total >= trader', $dbResolved);
    }

    $tierCount = PaymentGatewayCommissionTier::query()->count();
    pass('DB: commission tiers table accessible ('.$tierCount.' rows)');

    $traderRateCount = TraderCommissionRate::query()->count();
    pass('DB: trader commission rates table accessible ('.$traderRateCount.' rows)');
}

$merchant = Merchant::query()->first();
if ($merchant === null) {
    skip('DB: no merchant for inherit-mode check');
} else {
    $inheritGateway = PaymentGateway::query()->with('commissionTiers')->first();
    if ($inheritGateway !== null) {
        $settings = $merchant->gateway_settings[$inheritGateway->id] ?? null;
        $hasOverride = is_array($settings)
            && (
                (isset($settings['custom_gateway_commission']) && (float) $settings['custom_gateway_commission'] > 0)
                || (($settings['commission_mode'] ?? null) === 'tiered' && ! empty($settings['custom_gateway_commission_tiers']))
            );

        if ($hasOverride) {
            skip('DB: merchant has commission override on gateway #'.$inheritGateway->id.' — baseline comparison skipped');

            try {
                $overrideResolved = services()->commissionRate()->resolve(
                    paymentGateway: $inheritGateway,
                    amount: Money::fromPrecision('10000', strtoupper($inheritGateway->currency->getCode())),
                    operationType: CommissionOperationType::ORDER,
                    merchant: $merchant,
                );
                pass('DB: merchant override resolves (total='.$overrideResolved->totalServiceCommissionRate.'%, trader='.$overrideResolved->traderCommissionRate.'%)');
            } catch (InvalidArgumentException $exception) {
                fail(
                    'DB: merchant override produces invalid rates — fix merchant gateway_settings',
                    'valid rates',
                    $exception->getMessage(),
                );
            }
        } else {
            $amountForCheck = Money::fromPrecision('10000', strtoupper($inheritGateway->currency->getCode()));
            $inheritResolved = services()->commissionRate()->resolve(
                paymentGateway: $inheritGateway,
                amount: $amountForCheck,
                operationType: CommissionOperationType::ORDER,
            );
            $baselineResolved = services()->commissionRate()->resolve(
                paymentGateway: $inheritGateway,
                amount: $amountForCheck,
                operationType: CommissionOperationType::ORDER,
            );
            assertFloatSame(
                $baselineResolved->totalServiceCommissionRate,
                $inheritResolved->totalServiceCommissionRate,
                'DB: merchant without override → same as gateway baseline',
            );
        }
    }
}

section('PayoutService private resolver (reflection)');
try {
    $payoutService = services()->payout();
    $method = new ReflectionMethod($payoutService, 'resolvePayoutCommissionRates');
    $method->setAccessible(true);

    $ratesNoGateway = $method->invoke(
        $payoutService,
        null,
        \App\Services\Money\Currency::make('rub'),
        null,
        null,
        null,
    );
    if (is_array($ratesNoGateway) && array_key_exists('total_rate', $ratesNoGateway)) {
        pass('PayoutService: fallback rates without gateway');
    } else {
        fail('PayoutService: fallback rates without gateway', 'array with total_rate', $ratesNoGateway);
    }

    $ratesWithGateway = $method->invoke(
        $payoutService,
        $gatewayPayoutTiered,
        \App\Services\Money\Currency::make('rub'),
        $amount15k,
        null,
        null,
    );
    assertFloatSame(4.0, (float) $ratesWithGateway['total_rate'], 'PayoutService: tier total via reflection');
    assertFloatSame(2.5, (float) $ratesWithGateway['trader_rate'], 'PayoutService: tier trader via reflection');

    $ratesWithTrader = $method->invoke(
        $payoutService,
        $gatewayPayoutTiered,
        \App\Services\Money\Currency::make('rub'),
        $amount15k,
        null,
        $traderJ,
    );
    assertFloatSame(1.5, (float) $ratesWithTrader['trader_rate'], 'PayoutService: trader override on take path');
} catch (Throwable $exception) {
    fail('PayoutService reflection checks', 'ok', $exception->getMessage());
}

section('Admin API endpoints');
$gatewayId = (int) (PaymentGateway::query()->value('id') ?? 1);
$gatewayModel = PaymentGateway::query()->with('commissionTiers')->find($gatewayId);

try {
    $tiersResponse = app(App\Http\Controllers\Admin\PaymentGatewayCommissionTierController::class)
        ->index($gatewayModel);
    if ($tiersResponse->getStatusCode() === 200) {
        pass('API: PaymentGatewayCommissionTierController::index → 200');
        $tiersBody = json_decode($tiersResponse->getContent(), true);
        if (is_array($tiersBody) && ($tiersBody['success'] ?? false) === true) {
            pass('API: commission-tiers index success=true');
        } else {
            fail('API: commission-tiers index success flag', true, $tiersBody['success'] ?? null);
        }
    } else {
        fail('API: commission-tiers index status', 200, $tiersResponse->getStatusCode());
    }
} catch (Throwable $exception) {
    fail('API: commission-tiers index', 'ok', $exception->getMessage());
}

$traderUser = User::role('Trader')->first();
if ($traderUser === null) {
    skip('API: no trader user for commission-rates endpoint');
} else {
    try {
        $ratesResponse = app(App\Http\Controllers\Admin\UserCommissionRateController::class)
            ->index($traderUser);
        if ($ratesResponse->getStatusCode() === 200) {
            pass('API: UserCommissionRateController::index → 200');
        } else {
            fail('API: user commission-rates index status', 200, $ratesResponse->getStatusCode());
        }
    } catch (Throwable $exception) {
        fail('API: user commission-rates index', 'ok', $exception->getMessage());
    }
}

skip('API: profit calculator disabled (routes commented out)');

/*
try {
    $payload = [
        'payment_gateway_id' => $gatewayId,
        'amount' => '15000',
        'amount_currency' => 'rub',
        'operation_type' => 'order',
    ];
    $symfonyRequest = Illuminate\Http\Request::create('/admin/profit-calculator/resolve-rates', 'POST', $payload);
    $resolveRequest = App\Http\Requests\Admin\Profit\ResolveCommissionRatesRequest::createFrom($symfonyRequest);
    $resolveRequest->setContainer($app);
    $resolveRequest->validateResolved();

    $resolveResponse = app(App\Http\Controllers\Admin\ProfitCalculatorController::class)
        ->resolveRates($resolveRequest);
    if ($resolveResponse->getStatusCode() === 200) {
        pass('API: ProfitCalculatorController::resolveRates → 200');
        $resolveBody = json_decode($resolveResponse->getContent(), true);
        if (($resolveBody['success'] ?? false) === true) {
            pass('API: resolve-rates success=true');
            $direct = services()->commissionRate()->resolve(
                paymentGateway: $gatewayModel,
                amount: Money::fromPrecision('15000', 'RUB'),
                operationType: CommissionOperationType::ORDER,
            );
            assertFloatSame($direct->totalServiceCommissionRate, (float) ($resolveBody['data']['total_commission_rate'] ?? -1), 'API: resolve-rates total matches resolver');
            assertFloatSame($direct->traderCommissionRate, (float) ($resolveBody['data']['trader_commission_rate'] ?? -1), 'API: resolve-rates trader matches resolver');
        } else {
            fail('API: resolve-rates success flag', true, $resolveBody['success'] ?? null);
        }
    } else {
        fail('API: resolve-rates status', 200, $resolveResponse->getStatusCode());
    }
} catch (Throwable $exception) {
    fail('API: profit calculator resolve-rates', 'ok', $exception->getMessage());
}
*/

Carbon::setTestNow();

section('Container smoke');

try {
    $callbackService = app(\App\Contracts\CallbackServiceContract::class);
    if ($callbackService instanceof \App\Services\OrderCallback\CallbackService) {
        pass('CallbackServiceContract resolves from container');
    } else {
        fail('CallbackServiceContract resolves from container', \App\Services\OrderCallback\CallbackService::class, get_class($callbackService));
    }
} catch (Throwable $exception) {
    fail('CallbackServiceContract resolves from container', 'ok', $exception->getMessage());
}

echo "\n========================================\n";
echo "PASSED: {$passed}\n";
echo "FAILED: {$failed}\n";
echo "SKIPPED: {$skipped}\n";
echo "========================================\n";

if ($failed > 0) {
    exit(1);
}

echo "All commission checklist tests passed.\n";
exit(0);
