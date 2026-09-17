<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'username' => [
                'sometimes',
                'required',
                'string',
                new \App\Rules\AccountUsername($this->user()->username),
            ],
            'email' => [
                'nullable',
                'email',
                'max:191',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
        ];
    }
}
