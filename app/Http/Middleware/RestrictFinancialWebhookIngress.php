<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

final class RestrictFinancialWebhookIngress
{
    private const PATHS = ['api/charge/callback', 'api/webhook/sepay'];

    public function handle(Request $request, Closure $next): Response
    {
        // Match routing's decoded path, including encoded characters and trailing slashes.
        $path = trim(rawurldecode($request->getPathInfo()), '/');
        if (! in_array($path, self::PATHS, true)) {
            return $next($request);
        }

        $key = config('financial_webhook_ingress.peer_server_key', 'REMOTE_ADDR');
        // HTTP_* values originate in request headers and cannot prove a trusted peer.
        $validKey = is_string($key) && preg_match('/\A[A-Z][A-Z0-9_]*\z/D', $key)
            && ! str_starts_with($key, 'HTTP_');
        $peer = $validKey ? $request->server($key) : null;
        $peer = is_string($peer) ? $this->normalizePeer($peer) : null;
        $networks = config('financial_webhook_ingress.allowed_peer_networks', []);
        $networks = is_array($networks) ? array_values(array_filter($networks, 'is_string')) : [];

        if ($peer !== null && IpUtils::checkIp($peer, $networks)) {
            $request->attributes->set('financial_webhook_ingress_peer', $peer);

            return $next($request);
        }

        // RequestHandled monitoring runs even when this short-circuits the pipeline.
        // Prevent metrics from touching any configured DB/cache for denied traffic.
        $request->attributes->set('_traffic_enabled', false);

        return response()->json(['success' => false, 'message' => 'Forbidden.'], 403)
            ->header('Cache-Control', 'no-store');
    }

    private function normalizePeer(string $peer): ?string
    {
        if (! filter_var($peer, FILTER_VALIDATE_IP)) {
            return null;
        }
        $packed = inet_pton($peer);
        if ($packed !== false && strlen($packed) === 16
            && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            return inet_ntop(substr($packed, 12));
        }

        return $peer;
    }
}
