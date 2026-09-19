<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

class DecodableImage implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid() || $value->getSize() > 10 * 1024 * 1024) {
            $fail('Tệp ảnh không hợp lệ.');

            return;
        }
        $info = @getimagesize($value->getRealPath());
        $pixels = $info ? (int) $info[0] * (int) $info[1] : 0;
        $memoryLimit = ini_parse_quantity((string) ini_get('memory_limit'));
        $estimated = $pixels * 8 + (int) $value->getSize() + 16 * 1024 * 1024;
        if ($pixels < 1 || $pixels > 20_000_000
            || ($memoryLimit > 0 && $estimated > $memoryLimit - memory_get_usage(true))) {
            $fail('Ảnh không đọc được hoặc vượt giới hạn 20 triệu điểm ảnh.');

            return;
        }
        if (! function_exists('imagecreatefromstring')) {
            $fail('Máy chủ chưa cấu hình bộ giải mã ảnh GD.');

            return;
        }
        $decoded = @imagecreatefromstring(file_get_contents($value->getRealPath()));
        if ($decoded === false) {
            $fail('Ảnh bị hỏng hoặc thiếu dữ liệu; vui lòng gửi ảnh hoàn chỉnh.');

            return;
        }
        imagedestroy($decoded);
    }
}
