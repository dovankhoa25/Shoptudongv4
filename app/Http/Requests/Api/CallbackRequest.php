<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'request_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:255'],
            'serial' => ['required', 'string', 'max:255'],
            'callback_sign' => ['required', 'string', 'size:32'],
            'status' => ['required', 'integer', Rule::in([1, 2, 3, 4, 99, 100])],
            'value' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'amount' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'declared_value' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'trans_id' => ['nullable', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
