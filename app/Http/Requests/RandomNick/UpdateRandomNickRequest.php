<?php

namespace App\Http\Requests\RandomNick;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRandomNickRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'account' => 'required|string|max:255',
            'password' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'image' => 'nullable|file|image|max:2048',
            'status' => 'required|in:available,taken,deleted',
        ];
    }

    public function messages(): array
    {
        return [
            'account.required' => 'Tài khoản là bắt buộc.',
            'account.max' => 'Tài khoản không được vượt quá 255 ký tự.',
            'password.required' => 'Mật khẩu là bắt buộc.',
            'password.max' => 'Mật khẩu không được vượt quá 255 ký tự.',
            'description.max' => 'Mô tả không được vượt quá 1000 ký tự.',
            'image.image' => 'File phải là hình ảnh.',
            'image.max' => 'Kích thước hình ảnh không được vượt quá 2MB.',
            'status.required' => 'Trạng thái là bắt buộc.',
            'status.in' => 'Trạng thái không hợp lệ.',
        ];
    }
}
