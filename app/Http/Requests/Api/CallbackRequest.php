<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class CallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $stringFields = [
            'code',
            'serial',
            'callback_sign',
            'trans_id',
            'message',
        ];

        $normalized = [];

        foreach ($stringFields as $field) {
            $value = $this->input($field);

            if ($this->exists($field) && $value !== null && is_scalar($value)) {
                $normalized[$field] = (string) $value;
            }
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'request_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:255'],
            'serial' => ['required', 'string', 'max:255'],
            'callback_sign' => ['required', 'string', 'size:32'],
            'status' => ['required', 'integer', 'between:0,999'],
            'value' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'amount' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'declared_value' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'trans_id' => ['nullable', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
