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
        // CORS normally short-circuits preflight before this middleware. Do not
        // charge other OPTIONS requests against real browser operations either.
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $path = trim(rawurldecode($request->getPathInfo()), '/');
        $bucket = match (true) {
            $path === 'up' => 'health',
            $this->within($path, ['api/chat', 'chat', 'admin/chat']),
            in_array($path, ['api/broadcasting/auth', 'broadcasting/auth'], true) => 'chat',
            in_array($path, ['api/charge/callback', 'api/webhook/sepay', 'api/webhook/gpmpay'], true),
            $this->within($path, ['webhooks']) => 'financial',
            $this->within($path, ['app/v3']) => 'trading-tool',
            $this->within($path, ['app/nro-worker']) => 'worker',
            $this->within($path, ['app', 'api/app']) => 'legacy-tool',
            $this->within($path, [
                'api/auth', 'auth', 'oauth', 'login', 'register', 'forgot-password',
                'reset-password', 'confirm-password', 'password', 'logout',
                'verify-email', 'email/verification-notification',
            ]) => 'auth',
            $this->within($path, ['admin']) => 'admin',
            $this->within($path, ['api']) => $request->isMethod('GET') || $request->isMethod('HEAD')
                ? 'api-read' : 'api-write',
            $this->within($path, ['storage']) => 'storage',
            // Include unknown paths so rotating URLs cannot evade a finite budget.
            default => 'web',
        };
        $request->attributes->set('_ingress_bucket', $bucket);
        $limiter = match ($bucket) {
            'health' => 'health-ingress',
            'chat' => 'chat-ingress',
            'financial' => 'financial-webhook',
            default => 'public-ingress',
        };

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

    private function within(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
