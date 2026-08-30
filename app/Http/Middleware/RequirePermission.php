<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        abort_unless($user, 401, 'Unauthenticated.');
        abort_if($permissions === [], 403, 'Route chưa được cấu hình quyền.');

        if ($user->hasRole('super-admin')) {
            return $next($request);
        }

        abort_unless(
            $user->hasAnyPermission($permissions),
            403,
            'Bạn không có quyền thực hiện thao tác này.',
        );

        return $next($request);
    }
}
