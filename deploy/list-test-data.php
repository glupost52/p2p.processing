<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Order;
use App\Models\PaymentDetail;

echo "PaymentDetails:\n";
foreach (PaymentDetail::query()->withCount('orders')->get() as $pd) {
    echo sprintf(
        "%d | %s | %s | user=%d | orders=%d\n",
        $pd->id,
        $pd->name,
        $pd->detail,
        $pd->user_id,
        $pd->orders_count,
    );
}

echo "\nOrders:\n";
foreach (Order::query()->latest()->take(20)->get() as $o) {
    echo sprintf(
        "%d | %s | gw=%s | pd=%s | %s | %s | %s\n",
        $o->id,
        $o->uuid,
        $o->payment_gateway_id ?? 'null',
        $o->payment_detail_id ?? 'null',
        $o->status->value,
        $o->amount->toBeauty(),
        $o->created_at,
    );
}
