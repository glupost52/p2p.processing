#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\DTO\PaymentDetail\PaymentDetailCreateDTO;
use App\Enums\DetailType;
use App\Http\Requests\PaymentDetail\StoreRequest;
use App\Models\PaymentDetail;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

$passed = 0;
$failed = 0;

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

echo "\n=== Payment Detail Limits E2E ===\n";

$trader = User::role('Trader')->first();
if ($trader === null) {
    echo "FAIL: no trader user\n";
    exit(1);
}

$gateway = App\Models\PaymentGateway::query()->where('currency', 'rub')->first();
$gatewayId = $gateway?->id ?? 1;

$payloadWithEmptyLimits = [
    'name' => 'Limit Test',
    'detail' => '2202200220888888',
    'detail_type' => DetailType::CARD->value,
    'initials' => 'Limit Test User',
    'is_active' => true,
    'daily_limit' => null,
    'daily_successful_orders_limit' => null,
    'max_pending_orders_quantity' => null,
    'order_interval_minutes' => null,
    'currency' => 'rub',
    'payment_gateway_ids' => [$gatewayId],
    'min_order_amount' => null,
    'max_order_amount' => null,
];

$limitRules = [
    'daily_limit' => ['nullable', 'integer', 'min:0', 'max:100000000'],
    'max_pending_orders_quantity' => ['nullable', 'integer', 'min:0', 'max:100000000'],
    'order_interval_minutes' => ['nullable', 'integer', 'min:1'],
];

$limitValidator = Validator::make([
    'daily_limit' => $payloadWithEmptyLimits['daily_limit'],
    'max_pending_orders_quantity' => $payloadWithEmptyLimits['max_pending_orders_quantity'],
    'order_interval_minutes' => $payloadWithEmptyLimits['order_interval_minutes'],
], $limitRules);

if ($limitValidator->fails()) {
    fail('Limit rules accept empty optional limits', 'valid', $limitValidator->errors()->toArray());
} else {
    pass('Limit rules accept empty optional limits');
}

while (PaymentDetail::query()->where('detail', $payloadWithEmptyLimits['detail'])->exists()) {
    $payloadWithEmptyLimits['detail'] = '2202200220'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

try {
    $paymentDetail = services()->paymentDetail()->create(PaymentDetailCreateDTO::makeFromRequest(
        array_merge($payloadWithEmptyLimits, [
            'user_id' => $trader->id,
            'user_device_id' => null,
            'daily_limit' => '',
            'max_pending_orders_quantity' => '',
            'order_interval_minutes' => '',
        ]),
    ));
    pass('Created payment detail #'.$paymentDetail->id.' with empty limits');

    if ($paymentDetail->daily_limit === null) {
        pass('daily_limit stored as null');
    } else {
        fail('daily_limit stored as null', null, $paymentDetail->daily_limit?->toBeauty());
    }

    if ((int) $paymentDetail->max_pending_orders_quantity === 0) {
        pass('max_pending_orders_quantity stored as 0 (disabled)');
    } else {
        fail('max_pending_orders_quantity stored as 0', 0, $paymentDetail->max_pending_orders_quantity);
    }

    $paymentDetail->update(['archived_at' => now()]);
    pass('Cleanup: archived test payment detail');
} catch (Throwable $exception) {
    fail('Create payment detail with empty limits', 'success', $exception->getMessage());
}

echo "\n========================================\n";
echo "PASSED: {$passed}\n";
echo "FAILED: {$failed}\n";
echo "========================================\n";

exit($failed > 0 ? 1 : 0);
