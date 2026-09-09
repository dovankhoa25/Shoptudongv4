<?php

use App\Http\Controllers\AppAuto\AppBotController;
use App\Http\Controllers\AppAuto\AppGemBotController;
use App\Http\Controllers\AppAuto\AppGemTransactionController;
use App\Http\Controllers\AppAuto\AppGoldTransactionController;
use App\Http\Controllers\AppAuto\VersionTwo\AppBotVersionTwoController;
use App\Http\Controllers\AppAuto\VersionTwo\AppGemBotVersionTwoController;
use App\Http\Controllers\AppAuto\VersionTwo\AppGemTransactionVersionTwoController;
use App\Http\Controllers\AppAuto\VersionTwo\AppGoldTransactionVersionTwoController;
use App\Http\Controllers\AppAuto\VersionTwo\AppServerVersionTwoController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\NroWorkerController;
use App\Http\Middleware\NroWorkerKey;

Route::prefix('v1')->middleware('app')->group(function () {
    Route::get('/bots', [AppBotController::class, 'index']);
    Route::put('/bots/{id}', [AppBotController::class, 'update']);


    Route::get('/gold-transactions', [AppGoldTransactionController::class, 'index']);
    Route::put('/gold-transactions/{id}', [AppGoldTransactionController::class, 'update']);





    // ngọc
    Route::get('/gem/bots', [AppGemBotController::class, 'index']);
    Route::put('/gem/bots/{id}', [AppGemBotController::class, 'update']);


    Route::get('/gem-transactions', [AppGemTransactionController::class, 'index']);
    Route::put('/gem-transactions/{id}', [AppGemTransactionController::class, 'update']);
});


Route::prefix('v2')->middleware('app')->group(function () {

    Route::get('/servers', [AppServerVersionTwoController::class, 'index']);
    Route::get('/servers/login', [AppServerVersionTwoController::class, 'login']);


    Route::get('/bots', [AppBotVersionTwoController::class, 'index']);
    Route::put('/bots/{id}', [AppBotVersionTwoController::class, 'update']);


    Route::get('/gold-transactions', [AppGoldTransactionVersionTwoController::class, 'index']);
    Route::put('/gold-transactions/{id}', [AppGoldTransactionVersionTwoController::class, 'update']);





    // ngọc
    Route::get('/gem/bots', [AppGemBotVersionTwoController::class, 'index']);
    Route::put('/gem/bots/{id}', [AppGemBotVersionTwoController::class, 'update']);


    Route::get('/gem-transactions', [AppGemTransactionVersionTwoController::class, 'index']);
    Route::put('/gem-transactions/{id}', [AppGemTransactionVersionTwoController::class, 'update']);



    // // thêm khi test
    // Route::post('/bots', [AppBotVersionTwoController::class, 'store']);
    // Route::post('/gold-transactions', [AppGoldTransactionVersionTwoController::class, 'store']);

    // Route::post('/gem/bots', [AppGemBotVersionTwoController::class, 'store']);
    // Route::post('/gem-transactions', [AppGemTransactionVersionTwoController::class, 'store']);
});

// Each NRO machine has its own revocable Bearer key.
Route::prefix('nro-worker')->middleware([NroWorkerKey::class, 'throttle:600,1'])->group(function () {
    Route::get('accounts', [NroWorkerController::class, 'accounts']);
    Route::post('accounts/{id}/release', [NroWorkerController::class, 'release'])->whereNumber('id');
    Route::post('claim', [NroWorkerController::class, 'claim']);
    Route::post('jobs/{id}/warehouse-state', [NroWorkerController::class, 'warehouseState'])->whereNumber('id');
    Route::post('jobs/{id}/heartbeat', [NroWorkerController::class, 'heartbeat'])->whereNumber('id');
    Route::post('jobs/{id}/complete', [NroWorkerController::class, 'complete'])->whereNumber('id');
    Route::post('jobs/{id}/progress', [NroWorkerController::class, 'progress'])->whereNumber('id');
    Route::post('jobs/{id}/ready', [NroWorkerController::class, 'ready'])->whereNumber('id');
    Route::post('jobs/{id}/trade-phase', [NroWorkerController::class, 'tradePhase'])->whereNumber('id');
    Route::post('jobs/{id}/begin-round', [NroWorkerController::class, 'beginRound'])->whereNumber('id');
});
