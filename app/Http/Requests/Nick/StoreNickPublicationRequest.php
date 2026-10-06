<?php

namespace App\Http\Requests\Nick;

use Illuminate\Foundation\Http\FormRequest;

class StoreNickPublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Same permission middleware as the legacy create route.
    }

    public function rules(): array
    {
        return [
            'request_id' => 'required|uuid',
            'account_name' => 'required|string|max:255',
            'account_password' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string|max:50000',
            'listing_type' => 'required|in:normal,vip',
            'category_id' => 'required|integer|exists:categories,id',
            'attribute_cache_json' => 'present|array|max:100',
            'attribute_cache_json.*.attribute_id' => 'required|integer|distinct',
            'attribute_cache_json.*.option_id' => 'required|integer',
            'upload_ids' => 'present|array|max:20|prohibits:urls',
            'upload_ids.*' => 'required|uuid|distinct',
            'urls' => 'present|array|max:20|prohibits:upload_ids',
            'urls.*' => 'required|string|url:http,https|max:2048',
            'images' => 'prohibited',
        ];
    }

    public function messages(): array
    {
        return [
            'upload_ids.max' => 'Mỗi nick được đăng tối đa 20 ảnh.',
            'urls.max' => 'Mỗi nick được đăng tối đa 20 URL ảnh.',
        ];
    }
}
