<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        channels: __DIR__.'/../routes/channels.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('app')
                ->prefix('app')
                ->group(base_path('routes/app.php'));

            Route::middleware('webhooks')
                ->prefix('webhooks')
                ->name('webhooks.')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\ApplyFrontendClientCors::class);

        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);
        $middleware->alias([
            'permission' => \App\Http\Middleware\RequirePermission::class,
            'https.required' => \App\Http\Middleware\RequireHttps::class,
            'app' => \App\Http\Middleware\CheckApiAppKey::class,
            // 'auth.broadcast' => \App\Http\Middleware\AuthenticateBroadcasting::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $exception): bool {
            return $request->is('api/*') || $request->expectsJson();
        });

        // $exceptions->render(function (AuthenticationException $e, $request) {
        //     return response()->json([
        //         'message' => 'Unauthenticated.'
        //     ], 401);
        // });
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            // Trường hợp web (FE hoặc Admin)
            return redirect()->guest(route('login'));
        });
    })->create();
