<?php

namespace App\Http\Requests\GameType;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGameTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:game_types,name,' . $this->route('gametype')->id,

            'icon' => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }
}
