<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionOperationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $payment_gateway_id
 * @property CommissionOperationType $operation_type
 * @property int $min_amount
 * @property int $max_amount
 * @property float $trader_commission_rate
 * @property float $total_service_commission_rate
 * @property int $sort_order
 * @property PaymentGateway $paymentGateway
 */
class PaymentGatewayCommissionTier extends Model
{
    protected $fillable = [
        'payment_gateway_id',
        'operation_type',
        'min_amount',
        'max_amount',
        'trader_commission_rate',
        'total_service_commission_rate',
        'sort_order',
    ];

    protected $casts = [
        'operation_type' => CommissionOperationType::class,
        'min_amount' => 'integer',
        'max_amount' => 'integer',
        'trader_commission_rate' => 'float',
        'total_service_commission_rate' => 'float',
        'sort_order' => 'integer',
    ];

    public function paymentGateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class);
    }
}
