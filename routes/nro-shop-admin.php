<?php
use App\Http\Controllers\Admin\NroShopController;
use Illuminate\Support\Facades\Route;
Route::prefix('admin/nro-shop')->name('admin.nro-shop.')->middleware(['auth', 'unlocked.user', 'throttle:120,1'])->group(function () {
    Route::get('/', [NroShopController::class, 'index'])->middleware('permission:nro-accounts.view,nro-accounts.manage,nicks.create,nicks.manage,item-listings.view,item-listings.manage,item-orders.view,item-orders.reconcile,nro-workers.manage,nro-settings.manage,nro-sale-policy.manage')->name('index');
    Route::post('worker-keys', [NroShopController::class, 'createKey'])->middleware('permission:nro-workers.manage')->name('worker-keys.store');
    Route::delete('worker-keys/{id}', [NroShopController::class, 'revokeKey'])->middleware('permission:nro-workers.manage')->name('worker-keys.revoke');
    Route::patch('accounts/{id}/settings', [NroShopController::class, 'settings'])->middleware('permission:nro-settings.manage')->name('accounts.settings');
    // Controller authorizes admin/super-admin OR the dedicated sale-policy permission.
    Route::patch('sale-policy', [NroShopController::class, 'salePolicy'])->name('sale-policy');
    Route::get('nick-attribute-fields', [NroShopController::class, 'draftNickAttributes'])->middleware('permission:nicks.create,nicks.manage')->name('nick-attribute-fields');
    Route::post('accounts/import', [NroShopController::class, 'importAccounts'])->middleware('permission:nro-accounts.manage')->name('accounts.import');
    Route::post('accounts', [NroShopController::class, 'store'])->middleware('permission:nro-accounts.manage')->name('accounts.store');
    Route::get('accounts/{id}', [NroShopController::class, 'detail'])->middleware('permission:nro-accounts.view,nro-accounts.manage,nicks.create,nicks.manage,item-listings.manage')->name('accounts.show');
    Route::patch('accounts/{id}', [NroShopController::class, 'updateAccount'])->middleware('permission:nro-accounts.manage')->name('accounts.update');
    Route::patch('accounts/{id}/password', [NroShopController::class, 'password'])->middleware('permission:nro-accounts.manage')->name('accounts.password');
    Route::post('accounts/{id}/scan', [NroShopController::class, 'scan'])->middleware('permission:nro-accounts.manage')->name('scan');
    Route::post('accounts/{id}/nick', [NroShopController::class, 'publishNick'])->middleware('permission:nicks.create,nicks.manage')->name('nick');
    Route::get('accounts/{id}/nick-attributes', [NroShopController::class, 'nickAttributes'])->middleware('permission:nicks.create,nicks.manage')->name('nick-attributes');
    Route::post('accounts/{id}/listings', [NroShopController::class, 'publishItems'])->middleware('permission:item-listings.manage')->name('listings.store');
    Route::get('accounts/{id}/listings', [NroShopController::class, 'accountListings'])->middleware('permission:item-listings.view,item-listings.manage')->name('accounts.listings');
    Route::patch('listings/{id}', [NroShopController::class, 'toggle'])->middleware('permission:item-listings.manage')->name('listings.update');
    Route::post('jobs/{id}/reconcile', [NroShopController::class, 'reconcile'])->middleware('permission:item-orders.reconcile')->name('reconcile');
});
