<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ResolveSecurityClientIp
{
    public function handle(Request $request, Closure $next)
    {
        $peer = (string) $request->server('REMOTE_ADDR');
        $request->attributes->set('security_peer_ip', $peer);
        Request::setTrustedProxies(config('access_security.trusted_proxies', []),
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        $secret = (string) config('access_security.proxy_secret');
        $ip = (string) $request->header('X-Shop-Client-IP');
        $agent = (string) $request->header('X-Shop-Client-Agent');
        $time = (string) $request->header('X-Shop-Client-Time');
        $signature = (string) $request->header('X-Shop-Client-Signature');
        $signed = $secret !== '' && strlen($secret) >= 32 && ctype_digit($time)
            && abs(time() - (int) $time) <= 60 && filter_var($ip, FILTER_VALIDATE_IP)
            && strlen($agent) <= 1000
            && hash_equals(hash_hmac('sha256', implode("\n", [
                $request->method(), $request->getPathInfo(), $time, $ip, $agent,
                hash('sha256', $request->getContent()),
            ]), $secret), $signature);
        if ($signed) {
            // Never combine authenticated forwarding with unauthenticated proxy headers.
            $request->headers->remove('X-Forwarded-For');
            $request->server->set('REMOTE_ADDR', $ip);
            $request->headers->set('User-Agent', $agent);
        }
        $request->attributes->set('security_ip_source', $signed ? 'signed_storefront' : ($request->isFromTrustedProxy() ? 'trusted_proxy' : 'peer'));

        return $next($request);
    }
}
