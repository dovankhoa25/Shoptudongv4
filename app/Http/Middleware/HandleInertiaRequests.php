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
            'auth' => [
                'user' => $user?->only(['id', 'username', 'chat_display_name', 'email', 'balance', 'avatar', 'status']),
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
