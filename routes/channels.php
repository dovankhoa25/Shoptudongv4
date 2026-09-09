<?php

use App\Models\User;
use App\Services\Chat\ChatRealtimeChannel;
use Illuminate\Support\Facades\Broadcast;

$canReadChatRealtime = static fn (User $user): bool => ! $user->isLocked()
    && ($user->token() === null || $user->tokenCan('chat:read'));

Broadcast::channel('User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('Nro.Admin', function (User $user): bool {
    return !$user->isLocked() && ($user->hasRole('super-admin') || $user->hasAnyPermission([
        'nro-accounts.view', 'nro-accounts.manage', 'item-listings.view', 'item-listings.manage',
        'item-orders.view', 'item-orders.reconcile', 'nro-workers.manage', 'nro-settings.manage',
        'nro-sale-policy.manage', 'nicks.create', 'nicks.manage',
    ]));
});

Broadcast::channel('Chat.User.{id}.{credential}', function (User $user, int $id, string $credential) use ($canReadChatRealtime): bool {
    $expected = app(ChatRealtimeChannel::class)->currentForRequest(request(), refresh: true);

    return (int) $user->id === $id
        && $expected !== null
        && hash_equals($expected, ChatRealtimeChannel::name($id, $credential))
        && $canReadChatRealtime($user);
});
Broadcast::channel('authenticated', function ($user) {
    // Chỉ cần access token Passport hợp lệ.
    return $user !== null;
});

Broadcast::channel('Admin.realtime', function (User $user): bool {
    return $user->canViewAllAdminData();
});
