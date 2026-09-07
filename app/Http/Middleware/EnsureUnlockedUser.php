<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUnlockedUser
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->user() === null || $request->user()->isLocked(), 403, 'Tài khoản đã bị khóa.');

        return $next($request);
    }
}
