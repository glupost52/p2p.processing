<?php

namespace App\Http\Requests\Admin\PaymentGateway;

use App\Enums\CommissionOperationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncCommissionTiersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'operation_type' => ['required', Rule::in(CommissionOperationType::values())],
            'tiers' => ['present', 'array'],
            'tiers.*.min_amount' => ['required', 'integer', 'min:1'],
            'tiers.*.max_amount' => ['required', 'integer', 'min:1', 'gte:tiers.*.min_amount'],
            'tiers.*.trader_commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'tiers.*.total_service_commission_rate' => ['required', 'numeric', 'min:0', 'max:100', 'gte:tiers.*.trader_commission_rate'],
            'tiers.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
