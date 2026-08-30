<?php

namespace App\Http\Requests\Api\Profile;

use App\Models\Card;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCardHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => [
                'nullable',
                Rule::in([
                    Card::STATUS_PENDING,
                    Card::STATUS_CONFIRMED,
                    Card::STATUS_COMPLETED,
                    Card::STATUS_FAILED,
                ]),
            ],
            'search' => ['nullable', 'string', 'max:191'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
