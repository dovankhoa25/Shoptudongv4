<?php

namespace App\Http\Requests\RandomNick;

use Illuminate\Foundation\Http\FormRequest;

class BulkStoreRandomNickRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'random_box_id' => 'required|exists:random_boxes,id',
            'nick_data' => 'required|string|min:3',
            'shared_image' => 'nullable|file|image|max:2048',
        ];
    }

    public function messages(): array
    {
        return [
            'random_box_id.required' => 'Vui lòng chọn hộp random.',
            'random_box_id.exists' => 'Hộp random không tồn tại.',
            'nick_data.required' => 'Vui lòng nhập dữ liệu nick.',
            'nick_data.min' => 'Dữ liệu nick không hợp lệ.',
            'shared_image.image' => 'File phải là hình ảnh.',
            'shared_image.max' => 'Kích thước hình ảnh không được vượt quá 2MB.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $nickData = $this->input('nick_data');

            if ($nickData) {
                $lines = array_filter(array_map('trim', explode("\n", $nickData)));
                $validLines = 0;

                foreach ($lines as $line) {
                    $parts = array_map('trim', explode('|', $line));
                    if (count($parts) >= 2 && !empty($parts[0]) && !empty($parts[1])) {
                        $validLines++;
                    }
                }

                if ($validLines === 0) {
                    $validator->errors()->add('nick_data', 'Không tìm thấy dòng dữ liệu hợp lệ. Format: taikhoan|matkhau|mota');
                }
            }
        });
    }
}
