<?php

namespace App\Http\Resources;

use App\Models\PaymentGatewayCommissionTier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentGatewayCommissionTierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PaymentGatewayCommissionTier $this */
        return [
            'id' => $this->id,
            'operation_type' => $this->operation_type->value,
            'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount,
            'trader_commission_rate' => $this->trader_commission_rate,
            'total_service_commission_rate' => $this->total_service_commission_rate,
            'sort_order' => $this->sort_order,
        ];
    }
}
