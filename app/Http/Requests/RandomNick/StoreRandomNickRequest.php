<?php

namespace App\Http\Requests\RandomNick;

use Illuminate\Foundation\Http\FormRequest;

class StoreRandomNickRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'random_box_id' => 'required|exists:random_boxes,id',
            'account' => 'required|string|max:255',
            'password' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'image' => 'nullable|file|image|max:2048',
            'status' => 'in:available,taken,deleted',
        ];
    }

    public function messages(): array
    {
        return [
            'random_box_id.required' => 'Vui lòng chọn hộp random.',
            'random_box_id.exists' => 'Hộp random không tồn tại.',
            'account.required' => 'Tài khoản là bắt buộc.',
            'account.max' => 'Tài khoản không được vượt quá 255 ký tự.',
            'password.required' => 'Mật khẩu là bắt buộc.',
            'password.max' => 'Mật khẩu không được vượt quá 255 ký tự.',
            'description.max' => 'Mô tả không được vượt quá 1000 ký tự.',
            'image.image' => 'File phải là hình ảnh.',
            'image.max' => 'Kích thước hình ảnh không được vượt quá 2MB.',
            'status.in' => 'Trạng thái không hợp lệ.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (!$this->has('status')) {
            $this->merge(['status' => 'available']);
        }
    }
}
