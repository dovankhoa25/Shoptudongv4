<?php

namespace App\Http\Requests\Nick;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNickRequest extends FormRequest
{
    public function authorize()
    {
        // Tuỳ bạn — nếu dùng policy thì để true
        return true;
    }

    public function rules()
    {
        return [
            'account_name'      => 'required|string|max:255',
            'account_password'  => 'required|string|max:255',
            'price'             => 'required|numeric|min:0',
            'description'       => 'nullable|string',
            'listing_type'      => 'nullable|in:normal,vip', // hoặc các giá trị bạn cho phép
            'attribute_cache_json' => 'nullable|array',
            'attribute_cache_json.*.attribute_id' => 'required|integer|exists:attributes,id',
            'attribute_cache_json.*.option_id'    => 'required|integer|exists:attribute_options,id',
            'images.*'          => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'image_urls'        => 'nullable|json',
        ];
    }

    public function messages()
    {
        return [
            'account_name.required' => 'Vui lòng nhập tên tài khoản.',
            'account_password.required' => 'Vui lòng nhập mật khẩu.',
            'price.required' => 'Vui lòng nhập giá.',
            'price.numeric' => 'Giá phải là số.',
            'attribute_cache_json.*.attribute_id.required' => 'Thuộc tính không hợp lệ.',
            'attribute_cache_json.*.option_id.required' => 'Tuỳ chọn không hợp lệ.',
            'images.*.image' => 'Ảnh tải lên không đúng định dạng.',
            'images.*.mimes' => 'Ảnh phải là jpeg, png, jpg, gif hoặc webp.',
            'images.*.max' => 'Kích thước ảnh không được vượt quá 2MB.',
            'image_urls.json' => 'Danh sách URL ảnh phải là JSON hợp lệ.',
        ];
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
}
