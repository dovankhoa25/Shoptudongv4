<?php

namespace App\Helpers;

class AccountEncrypt
{
    public static function encrypt(string $plain): string
    {
        $key = config('services.account_key');

        // Đảm bảo key đủ 32 bytes cho AES-256
        $key = substr(hash('sha256', $key, true), 0, 32);

        $iv = random_bytes(16); // Vector khởi tạo

        $encrypted = openssl_encrypt(
            $plain,
            'AES-256-CBC',
            $key,
            0,
            $iv
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Could not encrypt data.');
        }

        return base64_encode($iv.$encrypted);
    }

    public static function decrypt(string $cipher): string
    {

        $key = config('services.account_key');

        $key = substr(hash('sha256', $key, true), 0, 32);

        $data = base64_decode($cipher);

        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);

        $decrypted = openssl_decrypt(
            $ciphertext,
            'AES-256-CBC',
            $key,
            0,
            $iv
        );
        if ($decrypted === false) {
            throw new \RuntimeException('Could not decrypt data.');
        }

        return $decrypted;
    }
}
