<?php

namespace App\Http\Requests\Attribute;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttributeOptionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }
    protected function prepareForValidation(): void
    {
        if ($this->filled('attribute_cache_json') && is_string($this->attribute_cache_json)) {
            $decoded = json_decode($this->attribute_cache_json, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->merge([
                    'attribute_cache_json' => $decoded,
                ]);
            }
        }
    }
    public function rules()
    {
        return [
            'option_value' => 'required|string',
            'status' => 'boolean',
        ];
    }
}
