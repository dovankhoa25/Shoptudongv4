<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Existing CSV roles stay compatible; a presence checker never handles stock. */
class ExclusiveCheckerRole implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) return;
        $roles = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($value))))));
        if (in_array('checker', $roles, true) && (count($roles) !== 1 || trim($value) !== 'checker')) {
            $fail('Bot kiểm tra chỉ dùng loại checker, không kết hợp với loại bán, nhập hoặc bot phụ.');
        }
    }
}
