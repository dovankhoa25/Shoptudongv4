<?php

namespace App\Http\Middleware;

use App\Services\FrontendClientRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyFrontendClientCors
{
    public function __construct(private readonly FrontendClientRegistry $clients) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*')) {
            config()->set('cors.allowed_origins', $this->clients->allowedOrigins());
        }

        return $next($request);
    }
}
