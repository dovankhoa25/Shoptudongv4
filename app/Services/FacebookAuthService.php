<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserAuthProvider;
use App\Models\UserSecurityLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

class FacebookAuthService
{
    public function verifyAccessToken(string $accessToken): ?array
    {
        $appId = (string) config('services.facebook.client_id');
        $appSecret = (string) config('services.facebook.client_secret');

        if ($appId === '' || $appSecret === '') {
            throw new LogicException('Facebook OAuth is not configured.');
        }

        $baseUrl = 'https://graph.facebook.com/'.config('services.facebook.graph_version', 'v23.0');

        try {
            $debugResponse = Http::acceptJson()
                ->withToken($appId.'|'.$appSecret)
                ->timeout(15)
                ->get($baseUrl.'/debug_token', [
                    'input_token' => $accessToken,
                ]);

            if ($debugResponse->failed()) {
                return null;
            }

            $tokenData = $debugResponse->json('data', []);

            if (! ($tokenData['is_valid'] ?? false)
                || (string) ($tokenData['app_id'] ?? '') !== $appId
                || empty($tokenData['user_id'])) {
                return null;
            }

            $profileResponse = Http::acceptJson()
                ->withToken($accessToken)
                ->timeout(15)
                ->get($baseUrl.'/me', [
                    'fields' => 'id,name,email,picture.type(large)',
                ]);

            if ($profileResponse->failed()) {
                return null;
            }

            $profile = $profileResponse->json();

            if (empty($profile['id']) || (string) $profile['id'] !== (string) $tokenData['user_id']) {
                return null;
            }

            return $profile;
        } catch (ConnectionException $exception) {
            Log::error('Unable to connect to Facebook Graph API', [
                'exception' => $exception::class,
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Facebook token verification exception', [
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    public function resolveAccessTokenUser(array $facebookUser, Request $request): User
    {
        return DB::transaction(function () use ($facebookUser, $request): User {
            $providerId = (string) $facebookUser['id'];
            $email = $facebookUser['email'] ?? null;
            $name = $facebookUser['name'] ?? null;
            $avatar = data_get($facebookUser, 'picture.data.url');

            $authProvider = UserAuthProvider::query()
                ->where('provider', 'facebook')
                ->where('provider_id', $providerId)
                ->first();

            if ($authProvider) {
                abort_if($authProvider->user->isLocked(), 423, 'This account is locked.');
                abort_unless($authProvider->is_enabled, 403, 'Facebook login is disabled for this account.');

                $authProvider->update([
                    'provider_email' => $email,
                    'provider_username' => $name,
                    'avatar' => $avatar,
                    'last_login_at' => now(),
                    'raw_data' => $facebookUser,
                ]);

                $authProvider->user->update([
                    'avatar' => $avatar ?: $authProvider->user->avatar,
                ]);

                return $authProvider->user;
            }

            $user = $email ? User::where('email', $email)->first() : null;

            if ($user) {
                abort_if($user->isLocked(), 423, 'This account is locked.');
            } else {
                $user = User::create([
                    'username' => $this->uniqueUsername($this->makeUsername($name, $email, $providerId)),
                    'email' => $email,
                    'password' => null,
                    'avatar' => $avatar,
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => $email ? now() : null,
                ]);
            }

            UserAuthProvider::create([
                'user_id' => $user->id,
                'provider' => 'facebook',
                'provider_id' => $providerId,
                'provider_email' => $email,
                'provider_username' => $name,
                'avatar' => $avatar,
                'is_enabled' => true,
                'last_login_at' => now(),
                'raw_data' => $facebookUser,
            ]);

            UserSecurityLog::create([
                'user_id' => $user->id,
                'event' => 'oauth_linked',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'meta' => ['provider' => 'facebook'],
            ]);

            return $user;
        });
    }

    private function makeUsername(?string $name, ?string $email, string $providerId): string
    {
        $base = $email ? Str::before($email, '@') : Str::slug($name ?: '', '_');

        return $base !== '' ? $base : 'facebook_'.Str::substr($providerId, -8);
    }

    private function uniqueUsername(string $base): string
    {
        $base = Str::lower(Str::limit($base, 150, ''));
        $username = $base;
        $counter = 1;

        while (User::where('username', $username)->exists()) {
            $username = $base.'_'.$counter;
            $counter++;
        }

        return $username;
    }
}
