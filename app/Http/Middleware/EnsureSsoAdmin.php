<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSsoAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            in_array((string) $request->user()?->id, config('sso.admin_user_ids'), true),
            403,
            'SSO administrator access is required.',
        );

        abort_if(config('access_security.admin_approval_required')
            && ! app(\App\Services\AdminAccessService::class)->approved($request->user(), $request),
            403, 'IP và thiết bị quản trị chưa được duyệt.');

        return $next($request);
    }
}
