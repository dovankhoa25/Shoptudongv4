<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

class FirstPartyClientService
{
    public function validate(Request $request, string $clientId): Client
    {
        $client = Passport::client()->newQuery()
            ->whereKey($clientId)
            ->where('revoked', false)
            ->where('is_first_party', true)
            ->where('allows_direct_login', true)
            ->first();

        if (! $client || ! $this->originIsAllowed($request, $client)) {
            throw ValidationException::withMessages([
                'client_id' => 'This client is not allowed to use direct login.',
            ]);
        }

        return $client;
    }

    private function originIsAllowed(Request $request, Client $client): bool
    {
        $origin = $request->headers->get('Origin');
        $allowedOrigins = json_decode($client->getRawOriginal('allowed_origins') ?: '[]', true);

        return is_string($origin)
            && is_array($allowedOrigins)
            && in_array(rtrim($origin, '/'), array_map(
                fn (string $allowedOrigin): string => rtrim($allowedOrigin, '/'),
                $allowedOrigins,
            ), true);
    }
}
