<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\UserSecurityLog;
use App\Services\GoogleAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    public function __construct(private readonly GoogleAuthService $google) {}

    public function redirect(string $provider): RedirectResponse
    {
        abort_unless($provider === 'google', 404);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless($provider === 'google', 404);

        try {
            $user = $this->google->resolveExistingUser(
                Socialite::driver('google')->user(),
                $request,
            );
            abort_unless(
                $user->can(Permission::DashboardView->value),
                403,
                'This account cannot access the admin area.',
            );
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('login')->with(
                'error',
                'Tài khoản Google này chưa được cấp quyền truy cập quản trị.',
            );
        }

        Auth::login($user);
        $request->session()->regenerate();

        LoginAttempt::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'provider' => 'google',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'is_success' => true,
        ]);

        UserSecurityLog::create([
            'user_id' => $user->id,
            'event' => 'login_success',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'meta' => ['provider' => 'google', 'channel' => 'web'],
        ]);

        return redirect()->intended(route('admin.home', absolute: false));
    }
}
