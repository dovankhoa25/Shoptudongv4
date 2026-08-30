<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function failedValidation(\Illuminate\Contracts\Validation\Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'errors' => $validator->errors(),
        ], 422));
    }

    public function rules(): array
    {
        return [
            'game_type_id' => 'required|exists:game_types,id',
            'name' => 'required|string|max:255|unique:categories,name,' . $this->route('category')->id,
            'image' => 'nullable|string|max:255',
            'template' => 'nullable|string',
            'is_public' => 'boolean',
            'status' => 'in:active,inactive,maintenance',
            'sort_order' => 'nullable|integer|min:0',
        ];
    }
    protected function prepareForValidation()
    {
        if ($this->has('is_public')) {
            $this->merge([
                'is_public' => filter_var($this->is_public, FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }
}
