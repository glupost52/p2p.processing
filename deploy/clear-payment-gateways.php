<?php

declare(strict_types=1);

/**
 * Remove all payment gateways and related links (fresh start).
 * Run: php8.3 deploy/clear-payment-gateways.php
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Merchant;
use App\Models\PaymentGateway;
use Illuminate\Support\Facades\DB;

$gatewayCount = PaymentGateway::query()->count();

if ($gatewayCount === 0) {
    echo "No payment gateways to delete.\n";
    exit(0);
}

$logos = PaymentGateway::query()
    ->whereNotNull('logo')
    ->pluck('logo')
    ->filter()
    ->unique()
    ->values()
    ->all();

$pivotCount = DB::table('payment_detail_payment_gateway')->count();
$smsParserCount = DB::table('sms_parsers')->whereNotNull('payment_gateway_id')->count();
$merchantCount = Merchant::query()->whereNotNull('gateway_settings')->count();

DB::transaction(function (): void {
    DB::table('payment_detail_payment_gateway')->delete();

    if (DB::getSchemaBuilder()->hasTable('sms_parsers')) {
        DB::table('sms_parsers')->update(['payment_gateway_id' => null]);
    }

    Merchant::query()->update(['gateway_settings' => []]);

    PaymentGateway::query()->delete();
});

$logosDir = storage_path('app/public/logos');
$deletedLogos = 0;

foreach ($logos as $logo) {
    $path = $logosDir . '/' . basename((string) $logo);

    if (is_file($path) && unlink($path)) {
        $deletedLogos++;
    }
}

echo "Deleted payment gateways: {$gatewayCount}\n";
echo "Cleared payment detail links: {$pivotCount}\n";
echo "Reset sms parser links: {$smsParserCount}\n";
echo "Reset merchant gateway settings: {$merchantCount}\n";
echo "Removed logo files: {$deletedLogos}\n";
