<?php

namespace App\Http\Requests\Merchant;

use App\Services\Commission\Exceptions\CommissionTierException;
use App\Services\Commission\MerchantGatewayCommissionValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateGatewaySettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'gateway_settings' => ['nullable', 'array'],
            'gateway_settings.*.active' => ['nullable', 'boolean'],
            'gateway_settings.*.custom_gateway_commission' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'gateway_settings.*.commission_mode' => ['nullable', 'string', Rule::in(['inherit', 'flat', 'tiered'])],
            'gateway_settings.*.custom_gateway_commission_tiers' => ['nullable', 'array'],
            'gateway_settings.*.custom_gateway_commission_tiers.*.min_amount' => ['required_with:gateway_settings.*.custom_gateway_commission_tiers', 'integer', 'min:1'],
            'gateway_settings.*.custom_gateway_commission_tiers.*.max_amount' => ['required_with:gateway_settings.*.custom_gateway_commission_tiers', 'integer', 'min:1'],
            'gateway_settings.*.custom_gateway_commission_tiers.*.total_service_commission_rate' => ['required_with:gateway_settings.*.custom_gateway_commission_tiers', 'numeric', 'min:0', 'max:100'],
            'gateway_settings.*.custom_gateway_reservation_time' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $gatewaySettings = $this->input('gateway_settings', []);

            if (! is_array($gatewaySettings) || $gatewaySettings === []) {
                return;
            }

            try {
                app(MerchantGatewayCommissionValidator::class)->validateGatewaySettings($gatewaySettings);
            } catch (CommissionTierException $exception) {
                $validator->errors()->add('gateway_settings', $exception->getMessage());
            }
        });
    }
}
