<?php

namespace App\Http\Middleware;

use App\Models\AccessIpBlock;
use Closure;
use Illuminate\Http\Request;

class EnforceIpBlock
{
    public function handle(Request $request, Closure $next)
    {
        // These integrations retain their dedicated key/signature authentication.
        if ($request->is('app/*', 'api/app/*', 'api/charge/callback', 'api/webhook/sepay', 'webhooks/*', 'up')
            || $request->routeIs('logout', 'api.logout', 'account.logout')) {
            return $next($request);
        }
        abort_if(AccessIpBlock::blocks($request->ip()), 403, 'IP của bạn đang bị chặn. Vui lòng liên hệ quản trị viên.');

        return $next($request);
    }
}
