<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Profit;

use App\Enums\CommissionOperationType;
use App\Services\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveCommissionRatesRequest extends FormRequest
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
            'amount_currency' => ['required', 'string', Rule::in(Currency::getAllCodes())],
            'amount' => ['required', 'numeric', 'min:0'],
            'payment_gateway_id' => ['required', 'integer', 'exists:payment_gateways,id'],
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'trader_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
