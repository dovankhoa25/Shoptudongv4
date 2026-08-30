<?php

namespace App\Http\Requests\Services;

use Illuminate\Foundation\Http\FormRequest;

class FilterServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Hoặc thêm logic kiểm tra quyền
    }

    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search'   => ['sometimes', 'string', 'max:255'],
            'status'   => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'per_page.integer' => 'Số lượng mỗi trang phải là số nguyên.',
            'per_page.min'     => 'Số lượng mỗi trang tối thiểu là 1.',
            'per_page.max'     => 'Số lượng mỗi trang tối đa là 100.',
            'search.string'    => 'Từ khóa tìm kiếm phải là chuỗi.',
            'status.boolean'   => 'Trạng thái phải là true hoặc false.',
        ];
    }
}
