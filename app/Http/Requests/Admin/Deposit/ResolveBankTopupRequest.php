<?php

namespace App\Http\Requests\Admin\Deposit;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveBankTopupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route permissions and the recipient's balance policy are both enforced.
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX,
                Rule::exists('users', 'id')->whereNull('deleted_at')],
        ];
    }
}
