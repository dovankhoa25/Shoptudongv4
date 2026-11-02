<?php

namespace App\Support;

use Throwable;

class PublicError
{
    public static function message(Throwable $exception): string
    {
        report($exception);

        return 'Không thể xử lý yêu cầu lúc này. Vui lòng kiểm tra trạng thái trước khi thử lại.';
    }
}
