<?php

namespace App\Services;

use DateTimeImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\ClientRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Bridge\ScopeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use Psr\Http\Message\ResponseInterface;

class FirstPartyUserTokenService
{
    private const SCOPES = [
        'profile:read',
        'profile:write',
        'sessions:read',
        'sessions:revoke',
        'balance:deposit',
    ];

    public function __construct(
        private readonly ClientRepository $clients,
        private readonly AccessTokenRepository $accessTokens,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly ScopeRepository $scopes,
        private readonly Encrypter $encrypter,
    ) {}

    public function issue(int|string $userId): array
    {
        $clientId = config('sso.password_client_id');
        $client = $clientId ? $this->clients->getClientEntity((string) $clientId) : null;

        if (! $client) {
            throw ValidationException::withMessages([
                'client_id' => 'Direct login is not configured on the SSO server.',
            ]);
        }

        return DB::transaction(function () use ($client, $userId): array {
            $privateKey = $this->privateKey();
            $scopes = collect(self::SCOPES)
                ->map(fn (string $scope) => $this->scopes->getScopeEntityByIdentifier($scope))
                ->filter()
                ->values()
                ->all();
            $scopes = $this->scopes->finalizeScopes(
                $scopes,
                'password',
                $client,
                (string) $userId,
            );

            $accessToken = $this->accessTokens->getNewToken($client, $scopes, (string) $userId);
            $accessToken->setIdentifier(bin2hex(random_bytes(40)));
            $accessToken->setExpiryDateTime((new DateTimeImmutable)->add(Passport::tokensExpireIn()));
            $accessToken->setPrivateKey($privateKey);
            $this->accessTokens->persistNewAccessToken($accessToken);

            $refreshToken = $this->refreshTokens->getNewRefreshToken();

            if (! $refreshToken) {
                throw new \RuntimeException('Passport did not create a refresh token.');
            }

            $refreshToken->setIdentifier(bin2hex(random_bytes(40)));
            $refreshToken->setExpiryDateTime((new DateTimeImmutable)->add(Passport::refreshTokensExpireIn()));
            $refreshToken->setAccessToken($accessToken);
            $this->refreshTokens->persistNewRefreshToken($refreshToken);

            $response = new BearerTokenResponse;
            $response->setAccessToken($accessToken);
            $response->setRefreshToken($refreshToken);
            $response->setPrivateKey($privateKey);
            $response->setEncryptionKey(Passport::tokenEncryptionKey($this->encrypter));

            return json_decode(
                $response->generateHttpResponse(app(ResponseInterface::class))->getBody()->__toString(),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        });
    }

    private function privateKey(): CryptKey
    {
        $key = str_replace('\\n', "\n", config('passport.private_key') ?? '');

        if (! $key) {
            $key = 'file://'.Passport::keyPath('oauth-private.key');
        }

        return new CryptKey($key, null, Passport::$validateKeyPermissions);
    }
}
