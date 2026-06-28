<?php

namespace App\Http\Requests\Dispute;

use Illuminate\Foundation\Http\FormRequest;

class StoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'receipt' => [
                'required',
                'mimes:jpeg,jpg,png,pdf',
                'max:5120',
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'receipt' => 'чек',
        ];
    }
}
