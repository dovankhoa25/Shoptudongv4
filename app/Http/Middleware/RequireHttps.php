<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireHttps
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->environment('production') && ! $request->isSecure()) {
            abort(400, 'HTTPS is required.');
        }

        return $next($request);
    }
}
