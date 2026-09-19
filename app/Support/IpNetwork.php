<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class IpNetwork
{
    public static function normalize(string $value): string
    {
        $parts = explode('/', trim($value));
        $binary = @inet_pton($parts[0]);
        $bits = $binary === false ? 0 : strlen($binary) * 8;
        $prefix = $parts[1] ?? (string) $bits;
        if ($binary === false || count($parts) > 2 || ! ctype_digit($prefix)
            || (int) $prefix < 1 || (int) $prefix > $bits) {
            throw ValidationException::withMessages(['network' => 'Nhập IP hoặc CIDR IPv4/IPv6 hợp lệ; không cho phép chặn toàn bộ Internet (/0).']);
        }
        $prefix = (int) $prefix;
        for ($i = 0; $i < strlen($binary); $i++) {
            $remaining = max(0, min(8, $prefix - $i * 8));
            $binary[$i] = chr(ord($binary[$i]) & ($remaining === 0 ? 0 : (255 << (8 - $remaining)) & 255));
        }

        return inet_ntop($binary).'/'.$prefix;
    }
}
