<?php

namespace App\Http\Middleware;

class Authenticate extends \Illuminate\Auth\Middleware\Authenticate
{
    protected function authenticate($request, array $guards)
    {
        parent::authenticate($request, $guards);

        // Check after the selected guard authenticates, including routes outside the main API group.
        if (! $request->routeIs('logout', 'api.logout', 'account.logout')) {
            abort_if($request->user()?->isLocked(), 403, 'Tài khoản đã bị khóa.');
        }
    }
}
