<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
Broadcast::channel('authenticated', function ($user) {
    // Chỉ cần access token Passport hợp lệ.
    return $user !== null;
});

Broadcast::channel('Admin.realtime', function (User $user): bool {
    return $user->canViewAllAdminData();
});
