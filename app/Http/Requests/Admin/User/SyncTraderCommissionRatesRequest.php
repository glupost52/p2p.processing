<?php

namespace App\Http\Requests\Admin\User;

use App\Enums\CommissionOperationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncTraderCommissionRatesRequest extends FormRequest
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
            'rates' => ['present', 'array'],
            'rates.*.payment_gateway_id' => ['required', 'integer', 'exists:payment_gateways,id'],
            'rates.*.operation_type' => ['required', Rule::in(CommissionOperationType::values())],
            'rates.*.min_amount' => ['nullable', 'integer', 'min:1', 'required_with:rates.*.max_amount'],
            'rates.*.max_amount' => ['nullable', 'integer', 'min:1', 'gte:rates.*.min_amount', 'required_with:rates.*.min_amount'],
            'rates.*.trader_commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'rates.*.is_active' => ['nullable', 'boolean'],
        ];
    }
}
