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

        return $next($request);
    }
}
