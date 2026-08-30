<?php

use App\Http\Controllers\Api\ApiGemBotController;
use App\Http\Controllers\Api\ApiGemOrderController;
use App\Http\Controllers\Api\ApiOrderController;
use App\Http\Controllers\Api\App\CarotRechargeController as AppCarotRechargeController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\GoogleLoginController;
use App\Http\Controllers\Api\Auth\FacebookLoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\PasswordController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Auth\RefreshTokenController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\BotController;
use App\Http\Controllers\Api\CardTypeController;
use App\Http\Controllers\Api\CarotRechargeController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\NickController;
use App\Http\Controllers\Api\OAuthClientController;
use App\Http\Controllers\Api\Profile\BalanceTransactionController;
use App\Http\Controllers\Api\Profile\CardHistoryController;
use App\Http\Controllers\Api\Profile\OrderController;
use App\Http\Controllers\Api\Profile\UserController;
use App\Http\Controllers\Api\RechargeController;
use App\Http\Controllers\Api\ServerController;
use App\Http\Controllers\Api\ServiceOrderController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\EnsureSsoAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Middleware\CheckToken;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| Giữ URL mặc định của backend, nhưng controller đã chuyển từ JWT sang
| Laravel Passport.
|
*/
Route::prefix('auth')->group(function (): void {
    Route::post('/google', GoogleLoginController::class)
        ->middleware('throttle:10,1');
    Route::post('/facebook', FacebookLoginController::class)
        ->middleware('throttle:10,1');

    Route::post('/register', [RegisterController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:5,1');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:5,1');
    Route::post('/refresh', RefreshTokenController::class)
        ->middleware('throttle:30,1');
});

Route::get('/card-types', [CardTypeController::class, 'index']);

Route::middleware('throttle:60,1')->group(function (): void {
    Route::post('/broadcasting/auth', function (Request $request) {
        return Broadcast::auth($request);
    })->middleware('auth:api');

    Route::get('/game-types-with-categories', [CategoryController::class, 'index']);
    Route::get('/categories/{slug}/nicks', [NickController::class, 'getByCategory']);
    Route::get('/nick/{id}', [NickController::class, 'show']);
    Route::get('/category/{slug}/service', [CategoryController::class, 'servicesBySlug']);
    Route::get('/categories/{categorySlug}/random-boxes/{boxId}', [NickController::class, 'getRandomBoxDetail']);

    /*
     * Route public bổ sung từ Vangtudong mà backend chưa có.
     */
    Route::get('/servers', [ServerController::class, 'index']);
    Route::get('/bots', [BotController::class, 'index']);
    Route::get('/gem/servers', [ServerController::class, 'getGem']);
    Route::get('/gem/bots', [ApiGemBotController::class, 'index']);
    Route::get('/server-prices', [HomeController::class, 'getServerPrices'])->name('api.server.prices');
    Route::get('/server-prices/{serverId}', [HomeController::class, 'getServerPriceById'])->name('api.server.price');
});

Route::post('/charge/callback', [RechargeController::class, 'callback'])
    ->middleware('throttle:financial-webhook');
Route::post('/webhook/sepay', [WebhookController::class, 'handle'])
    ->middleware('throttle:financial-webhook');

Route::prefix('app')->name('app.')->group(function (): void {
    Route::get('/carot/recharges/pending', [AppCarotRechargeController::class, 'pending'])->name('carot.pending');
    Route::post('/carot/recharges/{id}/success', [AppCarotRechargeController::class, 'markSuccess'])->name('carot.success');
    Route::post('/carot/recharges/{id}/failed', [AppCarotRechargeController::class, 'markFailed'])->name('carot.failed');
});

Route::middleware(['auth:api', 'throttle:60,1'])->group(function (): void {
    Route::get('/auth/user', [UserController::class, 'getUser'])
        ->middleware(CheckToken::using('profile:read'));
    Route::post('/auth/logout', LogoutController::class);

    Route::get('/me', [UserController::class, 'me'])
        ->middleware(CheckToken::using('profile:read'));

    Route::post('/purchase', [NickController::class, 'purchase']);
    Route::get('/user/profile', [UserController::class, 'getUser']);

    Route::prefix('profile')->name('api.profile.')->group(function (): void {
        Route::get('/', [UserController::class, 'show'])
            ->middleware(CheckToken::using('profile:read'))
            ->name('show');
        Route::patch('/', [UserController::class, 'update'])
            ->middleware([
                CheckToken::using('profile:read'),
                CheckToken::using('profile:write'),
            ])
            ->name('update');
        Route::get('/auth-providers', [UserController::class, 'providers'])
            ->middleware(CheckToken::using('profile:read'))
            ->name('auth-providers');
        Route::get('/devices', [UserController::class, 'devices'])
            ->middleware(CheckToken::using('profile:read'))
            ->name('devices');
        Route::get('/security-logs', [UserController::class, 'securityLogs'])
            ->middleware(CheckToken::using('profile:read'))
            ->name('security-logs');
        Route::get('/login-attempts', [UserController::class, 'loginAttempts'])
            ->middleware(CheckToken::using('profile:read'))
            ->name('login-attempts');
        Route::get('/punishments', [UserController::class, 'punishments'])
            ->middleware(CheckToken::using('profile:read'))
            ->name('punishments');
        Route::get('/nro-accounts', [UserController::class, 'nroAccounts'])
            ->middleware(CheckToken::using('profile:read'))
            ->name('nro-accounts');
        Route::get('/balance-transactions', [BalanceTransactionController::class, 'index'])
            ->middleware(CheckToken::using('profile:read'))
            ->name('balance-transactions');

        // Route trùng giữa hai dự án: ưu tiên OrderController mặc định của backend.
        Route::get('/orders', [OrderController::class, 'index'])->name('orders');
        Route::get('/history-card', [CardHistoryController::class, 'index'])
            ->middleware(CheckToken::using('profile:read'))
            ->name('history-card');
        Route::get('/balance-history', [UserController::class, 'getUserBalanceHistory'])->name('balance-history');
        Route::get('/services', [UserController::class, 'getUserServiceHistory'])->name('services');
        Route::get('/service-orders/stats', [UserController::class, 'getUserServiceStats'])
            ->name('service-orders.stats');
        Route::put('/services/{id}/cancel', [UserController::class, 'cancelServiceOrder'])->name('services.cancel');
        Route::get('/random', [UserController::class, 'getUserRandomHistory'])->name('random');
        Route::get('/random-stats', [UserController::class, 'getUserRandomStats'])->name('random-stats');
        Route::post('/avatar', [UserController::class, 'updateAvatar'])->name('avatar.update');
        Route::delete('/avatar', [UserController::class, 'deleteAvatar'])->name('avatar.delete');

        /*
         * Route profile bổ sung từ Vangtudong mà backend chưa có.
         */
        Route::get('/gems', [ApiGemOrderController::class, 'index'])->name('gems');
        Route::get('/withdrawal', [UserController::class, 'getWithdrawal'])->name('withdrawal.index');
        Route::post('/withdrawal', [UserController::class, 'storeWithdrawal'])->name('withdrawal.store');
    });

    Route::put('/auth/change-password', [PasswordController::class, 'update']);

    Route::prefix('account')->name('account.')->group(function (): void {
        Route::post('/logout', LogoutController::class)->name('logout');
        Route::put('/password', [PasswordController::class, 'update'])
            ->middleware(CheckToken::using('profile:write'))
            ->name('password.update');
        Route::get('/sessions', [SessionController::class, 'index'])
            ->middleware(CheckToken::using('sessions:read'))
            ->name('sessions.index');
        Route::delete('/sessions/{session}', [SessionController::class, 'destroy'])
            ->middleware(CheckToken::using('sessions:revoke'))
            ->name('sessions.destroy');
    });

    Route::post('/recharge/card', [RechargeController::class, 'store'])
        ->middleware([
            CheckToken::using('balance:deposit'),
            'throttle:balance-deposit',
        ]);
    Route::post('/charge', [RechargeController::class, 'store'])
        ->middleware([
            CheckToken::using('balance:deposit'),
            'throttle:balance-deposit',
        ]);

    Route::get('/carot/recharges', [CarotRechargeController::class, 'index']);
    Route::post('/carot/recharges', [CarotRechargeController::class, 'store']);
    Route::get('/carot/recharges/statistics', [CarotRechargeController::class, 'statistics']);
    Route::get('/carot/recharges/{id}', [CarotRechargeController::class, 'show']);

    Route::post('/service/orders', [ServiceOrderController::class, 'store']);
    Route::post('/categories/{categorySlug}/random-boxes/{boxId}/buy', [NickController::class, 'buyRandom']);
    Route::post('/categories/{categorySlug}/random-boxes/{boxId}/buy-nick/{nickId}', [NickController::class, 'buySpecificNick']);

    /*
     * Route giao dịch bổ sung từ Vangtudong mà backend chưa có.
     */
    Route::get('/gold/orders', [ApiOrderController::class, 'index'])->name('gold-orders.index');
    Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
    Route::post('/orders', [ApiOrderController::class, 'store'])->name('orders.store');
    Route::get('/gem/orders', [ApiGemOrderController::class, 'index'])->name('gem-orders.index');
    Route::post('/gem/orders', [ApiGemOrderController::class, 'store'])->name('gem-orders.store');

    Route::prefix('admin/oauth-clients')
        ->middleware([
            EnsureSsoAdmin::class,
            CheckToken::using('oauth-clients:manage'),
        ])
        ->name('admin.oauth-clients.')
        ->group(function (): void {
            Route::get('/', [OAuthClientController::class, 'index'])->name('index');
            Route::post('/', [OAuthClientController::class, 'store'])->name('store');
            Route::put('/{clientId}', [OAuthClientController::class, 'update'])->name('update');
            Route::post('/{clientId}/regenerate-secret', [OAuthClientController::class, 'regenerateSecret'])
                ->name('regenerate-secret');
            Route::delete('/{clientId}', [OAuthClientController::class, 'destroy'])->name('destroy');
        });
});
