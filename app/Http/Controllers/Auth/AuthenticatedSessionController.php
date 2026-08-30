<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Models\UserSecurityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
            'socialError' => session('error'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $user = User::query()
            ->where('username', $validated['username'])
            ->orWhere('email', $validated['username'])
            ->first();

        if (! $user || ! $user->password || ! Hash::check($validated['password'], $user->password)) {
            $this->recordAttempt($request, $user, false, 'invalid_credentials');

            throw ValidationException::withMessages([
                'username' => 'Invalid username, email, or password.',
            ]);
        }

        if ($user->isLocked()) {
            $this->recordAttempt($request, $user, false, 'user_locked');

            throw ValidationException::withMessages([
                'username' => 'This account is locked.',
            ]);
        }

        $provider = $user->authProviders()->where('provider', 'password')->first();

        if ($provider && ! $provider->is_enabled) {
            $this->recordAttempt($request, $user, false, 'provider_disabled');

            throw ValidationException::withMessages([
                'username' => 'Password username is disabled for this account.',
            ]);
        }

        Auth::login($user, $validated['remember'] ?? false);
        $request->session()->regenerate();

        $this->recordAttempt($request, $user, true);
        UserSecurityLog::create([
            'user_id' => $user->id,
            'event' => 'login_success',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'meta' => ['provider' => 'password', 'channel' => 'web'],
        ]);

        return redirect()->intended(route('admin.dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    private function recordAttempt(Request $request, ?User $user, bool $success, ?string $failureReason = null): void
    {
        LoginAttempt::create([
            'user_id' => $user?->id,
            'username' => $request->input('login'),
            'email' => filter_var($request->input('login'), FILTER_VALIDATE_EMAIL) ?: null,
            'provider' => 'password',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'is_success' => $success,
            'failure_reason' => $failureReason,
        ]);
    }
}
