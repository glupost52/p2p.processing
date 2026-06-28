<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionOperationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $payment_gateway_id
 * @property CommissionOperationType $operation_type
 * @property int|null $min_amount
 * @property int|null $max_amount
 * @property float $trader_commission_rate
 * @property bool $is_active
 * @property User $user
 * @property PaymentGateway $paymentGateway
 */
class TraderCommissionRate extends Model
{
    protected $fillable = [
        'user_id',
        'payment_gateway_id',
        'operation_type',
        'min_amount',
        'max_amount',
        'trader_commission_rate',
        'is_active',
    ];

    protected $casts = [
        'operation_type' => CommissionOperationType::class,
        'min_amount' => 'integer',
        'max_amount' => 'integer',
        'trader_commission_rate' => 'float',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentGateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    public function isFlat(): bool
    {
        return $this->min_amount === null && $this->max_amount === null;
    }
}
