<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Request;

class FirstPartyTokenService
{
    public function __construct(private readonly AuthorizationServer $server) {}

    public function issue(string $username, string $password): array
    {
        return $this->dispatch([
            'grant_type' => 'password',
            'username' => $username,
            'password' => $password,
            'scope' => implode(' ', [
                'profile:read',
                'profile:write',
                'sessions:read',
                'sessions:revoke',
                'balance:deposit',
            ]),
        ]);
    }

    public function refresh(string $refreshToken): array
    {
        return $this->dispatch([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);
    }

    public function ensureConfigured(): void
    {
        if (! config('sso.password_client_id') || ! config('sso.password_client_secret')) {
            throw ValidationException::withMessages([
                'client_id' => 'Direct login is not configured on the SSO server.',
            ]);
        }
    }

    private function dispatch(array $parameters): array
    {
        $this->ensureConfigured();

        $clientId = config('sso.password_client_id');
        $clientSecret = config('sso.password_client_secret');

        $request = Request::create(config('app.url').'/oauth/token', 'POST', [
            ...$parameters,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        try {
            $response = $this->server->respondToAccessTokenRequest(
                (new PsrHttpFactory)->createRequest($request),
                app(ResponseInterface::class),
            );
        } catch (OAuthServerException $exception) {
            throw ValidationException::withMessages([
                'login' => $exception->getMessage(),
            ]);
        }

        return json_decode($response->getBody()->__toString(), true, flags: JSON_THROW_ON_ERROR);
    }
}
