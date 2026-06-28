<?php

namespace App\Http\Resources;

use App\Models\TraderCommissionRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TraderCommissionRateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TraderCommissionRate $this */
        return [
            'id' => $this->id,
            'payment_gateway_id' => $this->payment_gateway_id,
            'operation_type' => $this->operation_type->value,
            'min_amount' => $this->min_amount,
            'max_amount' => $this->max_amount,
            'trader_commission_rate' => $this->trader_commission_rate,
            'is_active' => $this->is_active,
            'payment_gateway' => $this->whenLoaded('paymentGateway', fn () => [
                'id' => $this->paymentGateway?->id,
                'name' => $this->paymentGateway?->name_with_currency ?? $this->paymentGateway?->name,
            ]),
        ];
    }
}
