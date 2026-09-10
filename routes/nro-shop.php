<?php
use App\Http\Controllers\Api\NroShopController;
use Illuminate\Support\Facades\Route;

Route::prefix('nro-shop')->middleware(['throttle:120,1', \App\Http\Middleware\PublishNroChanges::class])->group(function () {
    Route::get('listings', [NroShopController::class, 'index']);
    Route::get('listings/{id}', [NroShopController::class, 'show'])->whereNumber('id');
    Route::middleware(['auth:api', 'unlocked.user'])->group(function () {
        Route::post('orders', [NroShopController::class, 'purchase']);
        Route::get('orders', [NroShopController::class, 'orders']);
        Route::post('orders/{id}/cancel', [NroShopController::class, 'cancel'])->whereNumber('id');
        Route::post('orders/{id}/receive', [NroShopController::class, 'receive'])->whereNumber('id');
    });
});
