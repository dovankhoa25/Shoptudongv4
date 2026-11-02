<?php

// app/Services/GoogleAuthService.php

namespace App\Services;

use App\Models\User;
use App\Models\UserAuthProvider;
use App\Models\UserSecurityLog;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\User as SocialiteOAuthUser;
use Throwable;

class GoogleAuthService
{
    public function resolveExistingUser(SocialiteUser $socialUser, Request $request): User
    {
        // Admin sign-in requires a previously linked Google identity, even if its email matches.
        return $this->resolveIdentity($socialUser, $request, existingOnly: true);
    }

    public function resolveUser(SocialiteUser $socialUser, Request $request): User
    {
        return $this->resolveIdentity($socialUser, $request);
    }

    private function resolveIdentity(
        SocialiteUser $socialUser,
        Request $request,
        bool $verifiedEmail = false,
        bool $allowEmailLink = false,
        bool $existingOnly = false,
    ): User {
        $providerId = (string) $socialUser->getId();
        $email = $socialUser->getEmail() ? $this->normalizeEmail($socialUser->getEmail()) : null;
        if ($providerId === '' || strlen($providerId) > 141) {
            throw ValidationException::withMessages(['email' => 'Thông tin tài khoản Google không hợp lệ.']);
        }

        $resolve = function () use ($socialUser, $request, $providerId, $email, $verifiedEmail, $allowEmailLink, $existingOnly): User {
            return DB::transaction(function () use ($socialUser, $request, $providerId, $email, $verifiedEmail, $allowEmailLink, $existingOnly): User {
                $name = $socialUser->getName() ?: $socialUser->getNickname();
                $avatar = $socialUser->getAvatar();
                $authProvider = $this->findProvider($providerId);

                if ($authProvider) {
                    return $this->useProvider($authProvider, $socialUser, $email);
                }

                abort_if($existingOnly, 403, 'Google account is not linked to an admin user.');
                $matches = $email
                    ? User::withTrashed()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->orderBy('id')->lockForUpdate()->limit(2)->get()
                    : collect();
                if ($matches->count() > 1) {
                    throw ValidationException::withMessages(['email' => 'Email đang gắn với nhiều tài khoản. Vui lòng liên hệ hỗ trợ để kiểm tra.']);
                }
                $user = $matches->first();

                if ($user) {
                    $this->assertUsableUser($user);
                    // Locking the existing user also serializes links from different Google identities.
                    if ($authProvider = $this->findProvider($providerId)) {
                        return $this->useProvider($authProvider, $socialUser, $email);
                    }
                    if (! $allowEmailLink || app(AdminAccessService::class)->isPrivileged($user)) {
                        throw ValidationException::withMessages([
                            'email' => 'Email đã thuộc một tài khoản. Vui lòng đăng nhập bằng phương thức đã liên kết hoặc đặt lại mật khẩu.',
                        ]);
                    }
                    $existingGoogle = $user->authProviders()->where('provider', 'google')->lockForUpdate()->first();
                    if ($existingGoogle) {
                        abort_unless($existingGoogle->is_enabled, 403, 'Google login is disabled for this account.');
                        throw ValidationException::withMessages(['email' => 'Tài khoản đã liên kết với một tài khoản Google khác. Vui lòng dùng phương thức đăng nhập đã liên kết.']);
                    }
                }

                if (! $user) {
                    $user = User::create([
                        'username' => $this->uniqueUsername($this->makeUsername($name, $email)),
                        'email' => $email,
                        'password' => null,
                        'avatar' => $avatar,
                        'status' => User::STATUS_ACTIVE,
                        'email_verified_at' => $email && $verifiedEmail ? now() : null,
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
            }, 3);
        };

        // These columns are not unique in older databases. Serialize first-time/retried
        // identities and normalized emails without changing or merging existing records.
        try {
            return Cache::lock('google-auth:identity:'.hash('sha256', $providerId), 30)->block(5,
                fn () => $email
                    ? Cache::lock('google-auth:email:'.hash('sha256', $email), 30)->block(5, $resolve)
                    : $resolve(),
            );
        } catch (LockTimeoutException) {
            throw new HttpResponseException(response()->json([
                'message' => 'Đăng nhập Google đang được xử lý. Vui lòng thử lại.',
            ], 503)->header('Retry-After', '2'));
        }
    }

    public function resolveAccessTokenUser(array $googleUser, Request $request): User
    {
        if (($googleUser['email_verified'] ?? null) !== true) {
            throw ValidationException::withMessages(['email' => 'Email chưa được Google xác thực.']);
        }
        $email = $this->normalizeEmail((string) $googleUser['email']);
        $socialUser = (new SocialiteOAuthUser)
            ->setRaw($googleUser)
            ->map([
                'id' => $googleUser['google_id'],
                'nickname' => null,
                'name' => $googleUser['name'] ?? null,
                'email' => $email,
                'avatar' => $googleUser['picture'] ?? null,
            ]);

        // Google is authoritative for Gmail and Workspace (hd), not every third-party
        // address with email_verified=true. Claims here come from Google's userinfo API.
        // https://developers.google.com/identity/sign-in/web/backend-auth
        $authoritative = Str::endsWith($email, '@gmail.com')
            || (is_string($googleUser['hd'] ?? null) && trim($googleUser['hd']) !== '');

        return $this->resolveIdentity($socialUser, $request, verifiedEmail: true, allowEmailLink: $authoritative);
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

            if (! is_array($data) || ! is_string($data['sub'] ?? null) || $data['sub'] === '' || strlen($data['sub']) > 141
                || ! is_string($data['email'] ?? null) || strlen($data['email']) > 191
                || ! filter_var($this->normalizeEmail($data['email']), FILTER_VALIDATE_EMAIL)) {
                Log::error('Invalid Google user data');

                return null;
            }

            return [
                'google_id' => $data['sub'],
                'email' => $this->normalizeEmail($data['email']),
                'name' => $data['name'] ?? '',
                'picture' => $data['picture'] ?? null,
                'email_verified' => ($data['email_verified'] ?? null) === true,
                'hd' => is_string($data['hd'] ?? null) ? $data['hd'] : null,
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
        $base = preg_replace('/[^\pL\pM\pN_.@+\-]+/u', '_', $base) ?: '';
        $base = Str::lower(Str::limit(trim($base, '_.@+-'), 150, ''));
        $base = $base !== '' ? $base : 'google_'.Str::lower(Str::random(12));
        $username = $base;
        $i = 1;

        while (User::withTrashed()->whereRaw('LOWER(username) = ?', [$username])->exists()) {
            $username = $base.'_'.$i;
            $i++;
        }

        return $username;
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function findProvider(string $providerId): ?UserAuthProvider
    {
        $matches = UserAuthProvider::query()->where('provider', 'google')->where('provider_id', $providerId)
            ->orderBy('id')->lockForUpdate()->limit(2)->get();
        if ($matches->count() > 1) {
            throw ValidationException::withMessages(['email' => 'Liên kết Google không rõ ràng. Vui lòng liên hệ hỗ trợ để kiểm tra.']);
        }

        return $matches->first();
    }

    private function assertUsableUser(User $user): void
    {
        abort_if($user->trashed(), 403, 'This account is no longer available.');
        abort_if($user->isLocked(), 423, 'This account is locked.');
    }

    private function useProvider(UserAuthProvider $provider, SocialiteUser $socialUser, ?string $email): User
    {
        $user = User::withTrashed()->whereKey($provider->user_id)->lockForUpdate()->first();
        abort_unless($user, 403, 'This account is no longer available.');
        $this->assertUsableUser($user);
        abort_unless($provider->is_enabled, 403, 'Google login is disabled for this account.');
        $provider->update([
            'provider_email' => $email,
            'provider_username' => $socialUser->getName() ?: $socialUser->getNickname(),
            'avatar' => $socialUser->getAvatar(),
            'last_login_at' => now(),
            'raw_data' => $socialUser->user,
        ]);

        return $user;
    }
}
