<?php

namespace App\Casts;

use App\Services\NroCredentialCipher;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** @implements CastsAttributes<string, string> */
final class NroCredential implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(NroCredentialCipher::class)->decryptString($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(NroCredentialCipher::class)->encryptString((string) $value);
    }
}
