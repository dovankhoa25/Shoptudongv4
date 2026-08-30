<?php

namespace App\Http\Requests\Nick;

use App\Models\Attribute;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreNickRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Nếu dùng Auth thì TRUE
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
            'account_name' => 'required|string|max:255',
            'account_password' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'listing_type' => 'nullable|in:normal,vip',

            'category_id' => 'required|exists:categories,id',
            'attributes' => 'nullable|array',
            'attributes.*' => 'nullable|integer|exists:attribute_options,id',

            'attribute_cache_json' => 'nullable|array',
            'attribute_cache_json.*.attribute_id' => 'required|integer|exists:attributes,id',
            'attribute_cache_json.*.option_id' => 'required|integer|exists:attribute_options,id',
            'attribute_cache_json.*.attribute_name' => 'nullable|string',
            'attribute_cache_json.*.option_value' => 'nullable|string',

            // File upload:
            'images' => 'nullable|array',
            'images.*' => 'file|image|max:5120', // max 5MB mỗi file

            // Hoặc URL upload:
            'image_urls' => 'nullable|string', // JSON encoded array
        ];
    }

    public function messages(): array
    {
        return [
            'account_name.required' => 'Vui lòng nhập tên tài khoản.',
            'account_name.unique' => 'Tài khoản đã tồn tại.',
            'account_password.required' => 'Vui lòng nhập mật khẩu.',
            'price.required' => 'Vui lòng nhập giá.',
            'price.numeric' => 'Giá phải là số.',
            'category_id.required' => 'Danh mục bắt buộc.',
            'category_id.exists' => 'Danh mục không tồn tại.',
            'images.*.image' => 'Tệp tải lên phải là hình ảnh.',
            'images.*.max' => 'Mỗi tệp ảnh tối đa 5MB.',
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

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $categoryId = $this->input('category_id');
            $payload = $this->input('attribute_cache_json', []);

            // Lấy danh sách attribute ID thuộc category này
            $category = Category::find($categoryId);

            if (!$category) {
                $validator->errors()->add('category_id', 'Danh mục không tồn tại.');
                return;
            }

            $expectedAttributeIDs = $category->attributes()->select('attributes.id')->pluck('id')->toArray();

            $submittedAttributeIDs = collect($payload)
                ->pluck('attribute_id')
                ->unique()
                ->toArray();

            // So sánh
            $missingAttributes = array_diff($expectedAttributeIDs, $submittedAttributeIDs);

            if (count($missingAttributes) > 0) {
                $validator->errors()->add(
                    // 'attribute_cache_json',
                    'Thuộc Tính',
                    'Bạn phải chọn giá trị cho tất cả các thuộc tính bắt buộc.'
                );
            }
        });
    }
}
