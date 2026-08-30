<?php

namespace App\Http\Requests\Api\App;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCarotRechargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_code' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('carot_recharges', 'transaction_code')->ignore($this->route('id')),
            ],
            'message' => ['nullable', 'string'],
            'api_response' => ['nullable', 'array'],
        ];
    }
}
