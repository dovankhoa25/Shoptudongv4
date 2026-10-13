<?php
use App\Http\Controllers\AppAuto\VersionThree\GemController;
use App\Http\Controllers\AppAuto\VersionThree\GoldController;
use App\Http\Controllers\AppAuto\VersionThree\HeartbeatController;
use App\Http\Controllers\AppAuto\VersionThree\ActivityController;
use App\Http\Middleware\VersionThree\TradingHmac;
use Illuminate\Support\Facades\Route;
Route::middleware([TradingHmac::class, 'throttle:3000,1'])->group(function (): void {
    Route::get('config', [GemController::class,'config']);
    Route::post('heartbeat', HeartbeatController::class);
    Route::post('activities', [ActivityController::class, 'batch']);
    Route::get('servers/login', [GemController::class,'servers']);
    Route::get('gem/bots', [GemController::class,'bots']);
    Route::put('gem/bots/{bot}', [GemController::class,'updateBot'])->whereNumber('bot');
    Route::post('gem/bots/{bot}/activity', [GemController::class,'activity'])->whereNumber('bot');
    Route::get('gem-transactions', [GemController::class,'orders']);
    Route::get('gem-transactions/{order}', [GemController::class,'order'])->whereNumber('order');
    Route::put('gem-transactions/{order}', [GemController::class,'updateOrder'])->whereNumber('order');
    Route::get('gold/bots', [GoldController::class,'bots']);
    Route::put('gold/bots/{bot}', [GoldController::class,'updateBot'])->whereNumber('bot');
    Route::post('gold/bots/{bot}/activity', [GoldController::class,'activity'])->whereNumber('bot');
    Route::get('gold-transactions', [GoldController::class,'orders']);
    Route::get('gold-transactions/{order}', [GoldController::class,'order'])->whereNumber('order');
    Route::put('gold-transactions/{order}', [GoldController::class,'updateOrder'])->whereNumber('order');
    Route::get('gold-transfers', [GoldController::class,'transfers']);
    Route::put('gold-transfers/{transfer}', [GoldController::class,'updateTransfer'])->whereUuid('transfer');
});

