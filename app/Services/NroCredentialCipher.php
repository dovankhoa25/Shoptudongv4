<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

final class NroCredentialCipher
{
    private ?Encrypter $encrypter = null;

    public function __construct()
    {
        $configured = config('nro-shop.credential_key');
        // Preserve existing installations until their dedicated key is configured.
        if ($configured === null || $configured === '') {
            return;
        }

        $key = is_string($configured) && str_starts_with($configured, 'base64:')
            ? base64_decode(substr($configured, 7), true) : $configured;
        if (!is_string($key) || !Encrypter::supported($key, 'AES-256-CBC')) {
            throw new InvalidArgumentException('NRO_CREDENTIAL_KEY must be a valid 32-byte AES-256 key.');
        }

        // Keep Laravel's authenticated payload format so existing NRO rows remain readable.
        $this->encrypter = new Encrypter($key, 'AES-256-CBC');
    }

    public function encryptString(string $value): string
    {
        return $this->encrypter?->encryptString($value) ?? Crypt::encryptString($value);
    }

    public function decryptString(string $value): string
    {
        if ($this->encrypter !== null) {
            try {
                return $this->encrypter->decryptString($value);
            } catch (DecryptException) {
                // Read pre-migration records using APP_KEY / APP_PREVIOUS_KEYS.
                // New writes always use the dedicated key when it is configured.
            }
        }

        return Crypt::decryptString($value);
    }
}
