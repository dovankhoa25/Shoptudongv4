<?php

// app/Services/GoogleAuthService.php

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
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\User as SocialiteOAuthUser;
use Throwable;

class GoogleAuthService
{
    public function resolveExistingUser(SocialiteUser $socialUser, Request $request): User
    {
        $providerUser = UserAuthProvider::query()
            ->where('provider', 'google')
            ->where('provider_id', (string) $socialUser->getId())
            ->first()?->user;
        $emailUser = $socialUser->getEmail()
            ? User::where('email', $socialUser->getEmail())->first()
            : null;

        abort_unless($providerUser || $emailUser, 403, 'Google account is not linked to an admin user.');

        return $this->resolveUser($socialUser, $request);
    }

    public function resolveUser(SocialiteUser $socialUser, Request $request): User
    {
        return DB::transaction(function () use ($socialUser, $request): User {
            $providerId = (string) $socialUser->getId();
            $email = $socialUser->getEmail();
            $name = $socialUser->getName() ?: $socialUser->getNickname();
            $avatar = $socialUser->getAvatar();

            $authProvider = UserAuthProvider::query()
                ->where('provider', 'google')
                ->where('provider_id', $providerId)
                ->first();

            if ($authProvider) {
                abort_if($authProvider->user->isLocked(), 423, 'This account is locked.');
                abort_unless($authProvider->is_enabled, 403, 'Google login is disabled for this account.');

                $authProvider->update([
                    'provider_email' => $email,
                    'provider_username' => $name,
                    'avatar' => $avatar,
                    'last_login_at' => now(),
                    'raw_data' => $socialUser->user,
                ]);

                return $authProvider->user;
            }

            $user = $email ? User::where('email', $email)->first() : null;

            if ($user) {
                abort_if($user->isLocked(), 423, 'This account is locked.');
            }

            if (! $user) {
                $user = User::create([
                    'username' => $this->uniqueUsername($this->makeUsername($name, $email)),
                    'email' => $email,
                    'password' => null,
                    'avatar' => $avatar,
                    'status' => User::STATUS_ACTIVE,
                    'email_verified_at' => $email ? now() : null,
                ]);
            }

            UserAuthProvider::create([
                'user_id' => $user->id,
                'provider' => 'google',
                'provider_id' => $providerId,
                'provider_email' => $email,
                'provider_username' => $name,
                'avatar' => $avatar,
                'is_enabled' => true,
                'last_login_at' => now(),
                'raw_data' => $socialUser->user,
            ]);

            UserSecurityLog::create([
                'user_id' => $user->id,
                'event' => 'oauth_linked',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'meta' => ['provider' => 'google'],
            ]);

            return $user;
        });
    }

    public function resolveAccessTokenUser(array $googleUser, Request $request): User
    {
        $socialUser = (new SocialiteOAuthUser)
            ->setRaw($googleUser)
            ->map([
                'id' => $googleUser['google_id'],
                'nickname' => null,
                'name' => $googleUser['name'] ?? null,
                'email' => $googleUser['email'],
                'avatar' => $googleUser['picture'] ?? null,
            ]);

        return $this->resolveUser($socialUser, $request);
    }

    public function verifyAccessToken(string $accessToken): ?array
    {
        try {
            // Log::info('Verifying Google token', [
            //     'token_length' => strlen($accessToken),
            // ]);

            $response = Http::acceptJson()
                ->withToken($accessToken)
                ->timeout(15)
                ->get('https://www.googleapis.com/oauth2/v3/userinfo');

            // Log::info('Google API response', [
            //     'status' => $response->status(),
            //     'success' => $response->successful(),
            //     'has_body' => !empty($response->body()),
            // ]);

            if ($response->failed()) {
                // Log::error('Google API request failed', [
                //     'status' => $response->status(),
                //     'body' => $response->body(),
                //     'headers' => $response->headers(),
                // ]);
                return null;
            }

            $data = $response->json();

            // Log::info('Google user data received', [
            //     'has_sub' => !empty($data['sub']),
            //     'has_email' => !empty($data['email']),
            //     'email' => $data['email'] ?? null,
            // ]);

            if (empty($data['sub']) || empty($data['email'])) {
                Log::error('Invalid Google user data', ['data' => $data]);

                return null;
            }

            return [
                'google_id' => $data['sub'],
                'email' => $data['email'],
                'name' => $data['name'] ?? '',
                'picture' => $data['picture'] ?? null,
                'email_verified' => $data['email_verified'] ?? false,
            ];
        } catch (ConnectionException $exception) {
            Log::error('Unable to connect to Google userinfo endpoint', [
                'exception' => $exception::class,
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Google token verification exception', [
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    private function makeUsername(?string $name, ?string $email): string
    {
        if ($email) {
            return Str::before($email, '@');
        }

        if ($name) {
            return Str::slug($name, '_');
        }

        return 'google_'.Str::random(8);
    }

    private function uniqueUsername(string $base): string
    {
        $base = Str::lower(Str::limit($base, 150, ''));
        $username = $base;
        $i = 1;

        while (User::where('username', $username)->exists()) {
            $username = $base.'_'.$i;
            $i++;
        }

        return $username;
    }
}
