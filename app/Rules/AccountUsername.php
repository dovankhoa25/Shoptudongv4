<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AccountUsername implements ValidationRule
{
    public function __construct(private readonly ?string $existing = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->existing !== null) {
            if ($value !== $this->existing) {
                $fail('Tên đăng nhập không được phép thay đổi.');
            }

            return;
        }

        if (! is_string($value) || ! preg_match('/\A[\pL\pM\pN_.@+\-]+\z/u', $value)) {
            $fail('Tên đăng nhập chỉ được chứa chữ, số và các ký tự . _ @ + -');
        }
    }
}
