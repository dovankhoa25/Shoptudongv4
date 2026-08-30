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

Route::prefix('v1')->group(function () {
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


Route::prefix('v2')->group(function () {

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
