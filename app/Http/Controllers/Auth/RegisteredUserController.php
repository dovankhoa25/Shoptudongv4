<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserAuthProvider;
use App\Models\UserSecurityLog;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:191', 'unique:users,username'],
            'email' => ['nullable', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($validated, $request): User {
            $user = User::create([
                'username' => $validated['username'],
                'email' => $validated['email'] ?? null,
                'password' => $validated['password'],
                'status' => User::STATUS_ACTIVE,
            ]);

            UserAuthProvider::create([
                'user_id' => $user->id,
                'provider' => 'password',
                'provider_id' => $user->username,
                'provider_email' => $user->email,
                'provider_username' => $user->username,
                'last_login_at' => now(),
            ]);

            UserSecurityLog::create([
                'user_id' => $user->id,
                'event' => 'register_success',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'meta' => ['provider' => 'password', 'channel' => 'web'],
            ]);

            return $user;
        });

        event(new Registered($user));
        Auth::login($user);

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
