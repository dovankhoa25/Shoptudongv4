<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckApiAppKey
{
    public function handle(Request $request, Closure $next)
    {
        $apiKey = trim((string) $request->header('X-APP-KEY'));
        $validKey = trim((string) config('services.app_api_key'));

        // Fail closed: thiếu cấu hình hoặc thiếu header đều phải bị từ chối.
        if ($validKey === '' || $apiKey === '' || ! hash_equals($validKey, $apiKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Invalid APP API Key.',
            ], 401);
        }

        return $next($request);
    }
}
