<?php
namespace App\Http\Middleware\VersionThree;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
class TradingHmac {
    public function handle(Request $request, Closure $next): Response {
        $secret = (string) config('trading_v3.hmac_secret');
        abort_unless(config('trading_v3.enabled') && strlen($secret) >= 32, 503, 'Trading v3 is not configured.');
        $id = (string) $request->header('X-Tool-Id');
        $time = (string) $request->header('X-Tool-Timestamp');
        $nonce = (string) $request->header('X-Tool-Nonce');
        $signature = (string) $request->header('X-Tool-Signature');
        abort_unless(hash_equals((string) config('trading_v3.tool_id'), $id) && ctype_digit($time)
            && abs(time() - (int) $time) <= (int) config('trading_v3.clock_skew_seconds')
            && preg_match('/^[a-f0-9]{32}$/D', $nonce) && preg_match('/^[a-f0-9]{64}$/D', $signature), 401, 'Invalid tool authentication.');
        $canonical = implode("\n", [strtoupper($request->method()), $request->getRequestUri(), $id, $time, $nonce, hash('sha256', $request->getContent())]);
        abort_unless(hash_equals(hash_hmac('sha256', $canonical, $secret), $signature), 401, 'Invalid tool authentication.');
        abort_unless(Cache::add('trading-v3:nonce:'.hash('sha256', $id.$nonce), true, 190), 409, 'Authentication nonce was already used.');
        $response=$next($request);$response->headers->set('Cache-Control','no-store');return $response;
    }
}

