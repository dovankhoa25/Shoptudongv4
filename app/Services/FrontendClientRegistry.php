<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;

class FrontendClientRegistry
{
    private const CACHE_KEY = 'frontend-clients:allowed-origins:v1';

    /** @return list<string> */
    public function allowedOrigins(): array
    {
        $staticOrigins = config('cors.static_allowed_origins', []);

        if (! Schema::hasTable('oauth_clients')) {
            return $staticOrigins;
        }

        $dynamicOrigins = Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(5),
            fn (): array => Passport::client()->newQuery()
                ->where('is_first_party', true)
                ->where('revoked', false)
                ->when(
                    config('sso.password_client_id'),
                    fn ($query, $clientId) => $query->whereKeyNot($clientId),
                )
                ->pluck('allowed_origins')
                ->flatMap(function ($origins): array {
                    if (is_array($origins)) {
                        return $origins;
                    }

                    $decoded = json_decode((string) $origins, true);

                    return is_array($decoded) ? $decoded : [];
                })
                ->filter(fn ($origin): bool => is_string($origin) && $origin !== '')
                ->map(fn (string $origin): string => rtrim($origin, '/'))
                ->unique()
                ->values()
                ->all(),
        );

        return collect([...$staticOrigins, ...$dynamicOrigins])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
