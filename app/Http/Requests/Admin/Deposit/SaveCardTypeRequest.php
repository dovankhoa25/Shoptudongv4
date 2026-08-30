<?php

namespace App\Http\Requests\Admin\Deposit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCardTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'telco' => strtolower(trim((string) $this->input('telco'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'telco' => [
                'required',
                'string',
                'max:30',
                'regex:/^[a-z0-9_-]+$/',
                Rule::unique('card_types', 'telco')->ignore($this->route('cardType')),
            ],
            'discount_rate' => ['required', 'numeric', 'min:0', 'max:99.99'],
            'status' => ['required', 'boolean'],
        ];
    }
}
