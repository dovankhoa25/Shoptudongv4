<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class LogCardPartnerCallbackAttempt
{
    /**
     * Temporary ingress probe for TSR callbacks. Remove it from the route after testing.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedFields = [
            'request_id',
            'telco',
            'code',
            'serial',
            'declared_value',
            'value',
            'amount',
            'status',
            'message',
            'trans_id',
            'callback_sign',
        ];

        Log::info('TEMP card partner callback reached Laravel before validation', [
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'request_id' => $request->input('request_id'),
            'partner_status' => $request->input('status'),
            'content_type' => $request->header('content-type'),
            'content_length' => $request->header('content-length'),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            'present_fields' => array_values(array_filter(
                $expectedFields,
                fn (string $field): bool => $request->exists($field),
            )),
        ]);

        return $next($request);
    }
}
