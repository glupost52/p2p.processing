<?php

declare(strict_types=1);

/**
 * Delete test orders and payment details by ID.
 * Usage: php8.3 deploy/delete-test-data.php --orders=1,2 --payment-details=1
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Dispute;
use App\Models\MerchantApiRequestLog;
use App\Models\Order;
use App\Models\PaymentDetail;
use App\Models\SmsLog;
use Illuminate\Support\Facades\DB;

$orderIds = [];
$paymentDetailIds = [];

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--orders=')) {
        $orderIds = array_filter(array_map('intval', explode(',', substr($arg, 9))));
    }
    if (str_starts_with($arg, '--payment-details=')) {
        $paymentDetailIds = array_filter(array_map('intval', explode(',', substr($arg, 18))));
    }
}

if ($orderIds === [] && $paymentDetailIds === []) {
    fwrite(STDERR, "Nothing to delete. Pass --orders=ID,ID and/or --payment-details=ID\n");
    exit(1);
}

DB::transaction(function () use ($orderIds, $paymentDetailIds): void {
    if ($orderIds !== []) {
        $orders = Order::query()->whereIn('id', $orderIds)->get();
        foreach ($orders as $order) {
            Dispute::query()->where('order_id', $order->id)->delete();
            MerchantApiRequestLog::query()->where('order_id', $order->id)->delete();
            SmsLog::query()->where('order_id', $order->id)->update(['order_id' => null]);
            $order->delete();
            echo "Deleted order #{$order->id} ({$order->uuid})\n";
        }
    }

    if ($paymentDetailIds !== []) {
        $details = PaymentDetail::query()->whereIn('id', $paymentDetailIds)->get();
        foreach ($details as $detail) {
            $detail->paymentGateways()->detach();
            $detail->tags()->detach();
            $detail->delete();
            echo "Deleted payment detail #{$detail->id} ({$detail->detail})\n";
        }
    }
});

echo "Done.\n";
