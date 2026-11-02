<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

final class ThrottlePublicIngress
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim(rawurldecode($request->getPathInfo()), '/');
        $limiter = match (true) {
            $path === 'up' => 'health-ingress',
            $path === 'api/chat', str_starts_with($path, 'api/chat/'),
            $path === 'chat', str_starts_with($path, 'chat/'),
            $path === 'admin/chat', str_starts_with($path, 'admin/chat/'),
            in_array($path, ['api/broadcasting/auth', 'broadcasting/auth'], true) => 'chat-ingress',
            default => null,
        };

        if ($limiter === null) {
            return $next($request);
        }

        // Health probes and rejected bursts must not add metrics writes of their own.
        // This runs after trusted IP resolution/CORS, before sessions, auth and bindings.
        $trafficEnabled = $request->attributes->get('_traffic_enabled', false);
        $request->attributes->set('_traffic_enabled', false);

        return app(ThrottleRequests::class)->handle($request, function (Request $request) use ($next, $limiter, $trafficEnabled): Response {
            if ($limiter !== 'health-ingress') {
                $request->attributes->set('_traffic_enabled', $trafficEnabled);
            }

            return $next($request);
        }, $limiter);
    }
}
