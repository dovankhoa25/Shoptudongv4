<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class StartTrafficTiming
{
    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('_traffic_started', hrtime(true));
        return $next($request);
    }
}
