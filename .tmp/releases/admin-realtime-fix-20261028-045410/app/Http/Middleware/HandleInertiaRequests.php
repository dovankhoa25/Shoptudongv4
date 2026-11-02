<?php

namespace App\Http\Middleware;

use App\Services\Chat\ChatRealtimeChannel;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            // Runtime props keep websocket/auth bootstrap working with older
            // deployed Blade templates. Never send the private Ably key.
            'realtime' => fn () => [
                'public_key' => explode(':', (string) (config('broadcasting.connections.ably.public_key')
                    ?: config('broadcasting.connections.ably.key', '')))[0],
                'csrf_token' => $request->hasSession() ? $request->session()->token() : null,
            ],
            // JSON endpoints and redirects do not render shared page props. Defer
            // balance locking, media/role queries and the realtime lease until needed.
            'auth' => fn () => [
                'user' => ($user ? [...$user->only(['id', 'username', 'chat_display_name', 'email', 'avatar', 'status']), ...\App\Services\UserBalanceSnapshot::read((int)$user->id)] : null),
                'roles' => $user?->getRoleNames()->values() ?? [],
                'permissions' => $user?->getAllPermissions()->pluck('name')->values() ?? [],
                'is_super_admin' => $user?->hasRole('super-admin') ?? false,
                'realtime_channel' => $user
                    ? app(ChatRealtimeChannel::class)->currentForRequest($request)
                    : null,
            ],
            'flash' => function () use ($request) {
                return [
                    'success' => $request->session()->get('success'),
                    'error' => $request->session()->get('error'),
                    'info' => $request->session()->get('info'),
                ];
            },
        ];
    }
}
