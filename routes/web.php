<?php

use App\Enums\Permission;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\AttributeController;
use App\Http\Controllers\Admin\BotController;
use App\Http\Controllers\Admin\BotHistoryController;
use App\Http\Controllers\Admin\CardController;
use App\Http\Controllers\Admin\CardTypeController;
use App\Http\Controllers\Admin\CarotRechargeController;
use App\Http\Controllers\Admin\CategoryAttributeController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CategoryServiceController;
use App\Http\Controllers\Admin\CategoryTemplateController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DepositController;
use App\Http\Controllers\Admin\FieldController;
use App\Http\Controllers\Admin\FrontendClientController;
use App\Http\Controllers\Admin\GameTypeController;
use App\Http\Controllers\Admin\GemBotController;
use App\Http\Controllers\Admin\GemOrderController;
use App\Http\Controllers\Admin\GemPriceController;
use App\Http\Controllers\Admin\GoldPriceController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\NickBulkUpdateController;
use App\Http\Controllers\Admin\NickController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\RandomBoxController;
use App\Http\Controllers\Admin\RandomNickController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\ServerGameLoginController;
use App\Http\Controllers\Admin\ServiceController;
use App\Http\Controllers\Admin\ServiceFieldController;
use App\Http\Controllers\Admin\ServiceOrderController;
use App\Http\Controllers\Admin\SpinController;
use App\Http\Controllers\Admin\SpinResultController;
use App\Http\Controllers\Admin\SpinRewardController;
use App\Http\Controllers\Admin\SpinTicketController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\UserCategoryController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WithdrawalRequestController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => redirect()->route('login'));
// Route::get('/', function () {
//     return Inertia::render('Welcome', [
//         'canLogin' => Route::has('login'),
//         'canRegister' => Route::has('register'),
//         'laravelVersion' => Application::VERSION,
//         'phpVersion' => PHP_VERSION,
//     ]);
// });

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::prefix('admin')
    ->name('admin.')
    ->middleware('auth', 'throttle:30,1')
    ->group(function () {
        Route::get('/', function () {
            return Inertia::render('Admin/Page');
        })->middleware(Permission::middleware(Permission::DashboardView))
            ->name('home');

        // thống kê
        Route::get('/analytics', [AnalyticsController::class, 'index'])
            ->middleware(Permission::middleware(Permission::AnalyticsView))
            ->name('analytics.index');

        Route::prefix('frontend-clients')->name('frontend-clients.')->group(function (): void {
            Route::get('/', [FrontendClientController::class, 'index'])
                ->middleware(Permission::middleware(
                    Permission::FrontendClientsView,
                    Permission::FrontendClientsManage,
                ))
                ->name('index');
            Route::post('/', [FrontendClientController::class, 'store'])
                ->middleware(Permission::middleware(Permission::FrontendClientsManage))
                ->name('store');
            Route::put('/{clientId}', [FrontendClientController::class, 'update'])
                ->middleware(Permission::middleware(Permission::FrontendClientsManage))
                ->name('update');
            Route::patch('/{clientId}/status', [FrontendClientController::class, 'updateStatus'])
                ->middleware(Permission::middleware(Permission::FrontendClientsManage))
                ->name('status');
        });

        // Preview trước khi bulk update
        Route::post('/nicks/bulk-preview', [NickBulkUpdateController::class, 'getBulkUpdatePreview'])
            ->middleware(Permission::middleware(Permission::NicksManage))
            ->name('nicks.bulk-preview');

        // Admin bulk update
        Route::post('/nicks/bulk-update', [NickBulkUpdateController::class, 'adminBulkUpdate'])
            ->middleware(Permission::middleware(Permission::NicksManage))
            ->name('nicks.bulk-update');

        // Helper endpoints
        Route::get('/nicks/account-patterns', [NickBulkUpdateController::class, 'getAccountNamePatterns'])
            ->middleware(Permission::middleware(Permission::NicksManage))
            ->name('nicks.account-patterns');

        Route::get('/nicks/ctv-list', [NickBulkUpdateController::class, 'getCTVList'])
            ->middleware(Permission::middleware(Permission::NicksManage))
            ->name('nicks.ctv-list');

        Route::get('/nicks/roles-list', [NickBulkUpdateController::class, 'getRolesList'])
            ->middleware(Permission::middleware(Permission::NicksManage))
            ->name('nicks.roles-list');

        Route::put('/nicks/{nick}/status', [NickBulkUpdateController::class, 'ctvSingleUpdate'])
            ->middleware(Permission::middleware(Permission::NicksManage))
            ->name('ctv.nicks.update-status');

        // Quick toggle hide/pending
        Route::put('/nicks/{nick}/toggle-visibility', [NickBulkUpdateController::class, 'ctvQuickToggle'])
            ->middleware(Permission::middleware(Permission::NicksManage))
            ->name('nicks.toggle-visibility');

        // Users
        Route::prefix('users')->name('users.')->group(function () {
            Route::get('/', [UserController::class, 'index'])
                ->middleware(Permission::middleware(Permission::UsersView))
                ->name('index');
            Route::post('/', [UserController::class, 'store'])
                ->middleware(Permission::middleware(Permission::UsersCreate))
                ->name('store');
            Route::get('/ctv', [UserController::class, 'ctv'])
                ->middleware(Permission::middleware(Permission::UsersView))
                ->name('ctv');

            Route::put('/{user}', [UserController::class, 'update'])
                ->middleware(Permission::middleware(Permission::UsersUpdate))
                ->name('update'); // Cập nhật users
            // Route backend cũ chưa có method tương ứng, tạm comment để sửa sau.
            // Route::get('/roles', [UserController::class, 'roles'])->name('roles');
            // Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit');
            // Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy');
            // Route::delete('/{user}/lock', [UserController::class, 'destroy'])->name('lock'); // Xóa users
            Route::get('/search', [UserController::class, 'search'])
                ->middleware(Permission::middleware(Permission::UsersView))
                ->name('search'); // Chi tiết 1 users

            Route::get('/{user}/permissions', [UserController::class, 'getPermissions'])
                ->middleware(Permission::middleware(Permission::UsersManageRoles))
                ->name('permissions');

            // Cập nhật roles + permissions cho user
            Route::post('/{user}/assign', [UserController::class, 'assign'])
                ->middleware(Permission::middleware(Permission::UsersManageRoles))
                ->name('assign');
            // Route khóa tài khoản
            Route::post('/{user}/lock', [UserController::class, 'lockUser'])
                ->middleware(Permission::middleware(Permission::UsersLock))
                ->name('lock');
            Route::post('/{user}/unlock', [UserController::class, 'unlockUser'])
                ->middleware(Permission::middleware(Permission::UsersLock))
                ->name('unlock');
            Route::post('/{user}/balance', [UserController::class, 'adjustBalance'])
                ->middleware(Permission::middleware(Permission::UsersAdjustBalance))
                ->name('balance.adjust');

            // Route lấy categories của user
            Route::get('/{user}/categories', [UserCategoryController::class, 'index'])
                ->middleware(Permission::middleware(Permission::UserCategoriesManage))
                ->name('categories.index');
            Route::post('/{user}/categories', [UserCategoryController::class, 'syncCategories'])
                ->middleware(Permission::middleware(Permission::UserCategoriesManage))
                ->name('categories.sync');
        });

        Route::prefix('roles')->name('roles.')->group(function () {
            Route::get('/', [RoleController::class, 'index'])
                ->middleware(Permission::middleware(Permission::RolesView, Permission::RolesManage))
                ->name('index'); // Danh sách roles
            Route::post('/', [RoleController::class, 'store'])
                ->middleware(Permission::middleware(Permission::RolesManage))
                ->name('store'); // Lưu roles mới
            Route::get('/{user}', [RoleController::class, 'show'])
                ->middleware(Permission::middleware(Permission::RolesView, Permission::RolesManage))
                ->name('show'); // Chi tiết 1 roles
            // Route backend cũ chưa có method tương ứng, tạm comment để sửa sau.
            // Route::get('/create', [RoleController::class, 'create'])->name('create');
            // Route::get('/{user}/edit', [RoleController::class, 'edit'])->name('edit');
            Route::put('/{user}', [RoleController::class, 'update'])
                ->middleware(Permission::middleware(Permission::RolesManage))
                ->name('update'); // Cập nhật roles
            Route::delete('/{user}', [RoleController::class, 'destroy'])
                ->middleware(Permission::middleware(Permission::RolesManage))
                ->name('destroy'); // Xóa roles
            Route::delete('/{user}/lock', [RoleController::class, 'destroy'])
                ->middleware(Permission::middleware(Permission::RolesManage))
                ->name('lock'); // Xóa roles
            Route::get('/{id}/permissions', [RoleController::class, 'getPermissions'])
                ->middleware(Permission::middleware(Permission::RolesManage))
                ->name('permissions');
            Route::post('/{id}/permissions/update', [RoleController::class, 'updatePermissions'])
                ->middleware(Permission::middleware(Permission::RolesManage))
                ->name('permissions.update');
        });

        Route::prefix('games')->name('games.')->group(function () {

            Route::prefix('gametypes')->name('gametypes.')->group(function () {
                Route::get('/', [GameTypeController::class, 'index'])
                    ->middleware(Permission::middleware(Permission::GameTypesView, Permission::GameTypesManage))
                    ->name('index');
                Route::post('/', [GameTypeController::class, 'store'])
                    ->middleware(Permission::middleware(Permission::GameTypesManage))
                    ->name('store');
                Route::put('/{gametype}', [GameTypeController::class, 'update'])
                    ->middleware(Permission::middleware(Permission::GameTypesManage))
                    ->name('update');
                Route::delete('/{gametype}', [GameTypeController::class, 'destroy'])
                    ->middleware(Permission::middleware(Permission::GameTypesManage))
                    ->name('destroy');
            });

            Route::get('categories-game-types', [CategoryController::class, 'gameTypes'])
                ->middleware(Permission::middleware(Permission::CategoriesView, Permission::CategoriesManage))
                ->name('categories.gameTypes');

            // Route::resource('categories', CategoryController::class);

            // Route::get('/categories/{category}/attributes', [CategoryController::class, 'getAttributes']);

            Route::prefix('categories')->name('categories.')->group(function () {
                Route::get('/', [CategoryController::class, 'index'])
                    ->middleware(Permission::middleware(Permission::CategoriesView, Permission::CategoriesManage))
                    ->name('index');
                Route::get('/create', [CategoryController::class, 'create'])
                    ->middleware(Permission::middleware(Permission::CategoriesManage))
                    ->name('create');
                Route::post('/', [CategoryController::class, 'store'])
                    ->middleware(Permission::middleware(Permission::CategoriesManage))
                    ->name('store');
                Route::get('/{category}/edit', [CategoryController::class, 'edit'])
                    ->middleware(Permission::middleware(Permission::CategoriesManage))
                    ->name('edit');
                Route::put('/{category}', [CategoryController::class, 'update'])
                    ->middleware(Permission::middleware(Permission::CategoriesManage))
                    ->name('update');
                Route::delete('/{category}', [CategoryController::class, 'destroy'])
                    ->middleware(Permission::middleware(Permission::CategoriesManage))
                    ->name('destroy');

                // Route::get('/{category}/attributes', [CategoryController::class, 'getAttributes']);
                Route::get('/{category}/attributes', [CategoryController::class, 'getAttributes'])
                    ->middleware(Permission::middleware(
                        Permission::AttributesView,
                        Permission::AttributesManage,
                        Permission::NicksManage,
                    ))
                    ->name('attributes');
            });

            Route::prefix('attributes')->name('attributes.')->group(function () {
                Route::get('/', [AttributeController::class, 'index'])
                    ->middleware(Permission::middleware(Permission::AttributesView, Permission::AttributesManage))
                    ->name('index');
                Route::post('/', [AttributeController::class, 'store'])
                    ->middleware(Permission::middleware(Permission::AttributesManage))
                    ->name('store');
                Route::put('/{attribute}', [AttributeController::class, 'update'])
                    ->middleware(Permission::middleware(Permission::AttributesManage))
                    ->name('update');
                Route::delete('/{attribute}', [AttributeController::class, 'destroy'])
                    ->middleware(Permission::middleware(Permission::AttributesManage))
                    ->name('destroy');

                Route::get('/getattributes', [AttributeController::class, 'attribute'])
                    ->middleware(Permission::middleware(Permission::AttributesView, Permission::AttributesManage))
                    ->name('get');

                // option
                Route::prefix('options')->name('options.')->group(function () {
                    Route::post('/', [AttributeController::class, 'storeOption'])
                        ->middleware(Permission::middleware(Permission::AttributesManage))
                        ->name('store');
                    Route::put('/{option}', [AttributeController::class, 'updateOption'])
                        ->middleware(Permission::middleware(Permission::AttributesManage))
                        ->name('update');
                    Route::delete('/{option}', [AttributeController::class, 'destroyOption'])
                        ->middleware(Permission::middleware(Permission::AttributesManage))
                        ->name('destroy');
                });
            });

            Route::prefix('category-attributes')->name('category-attributes.')->group(function () {
                Route::get('/', [CategoryAttributeController::class, 'index'])
                    ->middleware(Permission::middleware(Permission::AttributesView, Permission::AttributesManage))
                    ->name('index');
                Route::post('/assign', [CategoryAttributeController::class, 'assign'])
                    ->middleware(Permission::middleware(Permission::AttributesManage))
                    ->name('assign');
                Route::delete('/remove', [CategoryAttributeController::class, 'remove'])
                    ->middleware(Permission::middleware(Permission::AttributesManage))
                    ->name('remove');
            });

            Route::prefix('accounts')->name('accounts.')->group(function () {
                Route::get('/', [NickController::class, 'index'])
                    ->middleware(Permission::middleware(Permission::NicksView, Permission::NicksManage))
                    ->name('index');
                Route::get('/create', [NickController::class, 'create'])
                    ->middleware(Permission::middleware(Permission::NicksManage))
                    ->name('create');
                Route::post('/', [NickController::class, 'store'])
                    ->middleware(Permission::middleware(Permission::NicksManage))
                    ->name('store');
                Route::get('/detail/{id}', [NickController::class, 'show'])
                    ->middleware(Permission::middleware(Permission::NicksView, Permission::NicksManage))
                    ->name('show');
                Route::put('/{nick}', [NickController::class, 'update'])
                    ->middleware(Permission::middleware(Permission::NicksManage))
                    ->name('update');
                Route::delete('/{account}', [NickController::class, 'destroy'])
                    ->middleware(Permission::middleware(Permission::NicksManage))
                    ->name('destroy');

                Route::prefix('history')->name('history.')->group(function () {
                    Route::get('/', [NickController::class, 'history'])
                        ->middleware(Permission::middleware(Permission::NicksView, Permission::NicksManage))
                        ->name('index');
                });
            });
        });

        // hoàn tiền nick refund
        Route::put('nick-orders/{order}/refund', [NickController::class, 'refundNick'])
            ->middleware(Permission::middleware(Permission::NicksRefund))
            ->name('refund');

        Route::prefix('cardtypes')->name('cardtypes.')->group(function () {
            Route::get('/', [CardTypeController::class, 'index'])
                ->middleware(Permission::middleware(Permission::CardTypesView, Permission::CardTypesManage))
                ->name('index');
            Route::post('/', [CardTypeController::class, 'store'])
                ->middleware(Permission::middleware(Permission::CardTypesManage))
                ->name('store');
            Route::put('/{cardtype}', [CardTypeController::class, 'update'])
                ->middleware(Permission::middleware(Permission::CardTypesManage))
                ->name('update');
            Route::delete('/{cardtype}', [CardTypeController::class, 'destroy'])
                ->middleware(Permission::middleware(Permission::CardTypesManage))
                ->name('destroy');
        });

        Route::prefix('cards')->name('cards.')->group(function () {
            Route::get('/', [CardController::class, 'index'])
                ->middleware(Permission::middleware(Permission::CardsView))
                ->name('index');
        });

        Route::prefix('services')->name('services.')->group(function () {
            Route::get('/', [ServiceController::class, 'index'])
                ->middleware(Permission::middleware(Permission::ServicesView, Permission::ServicesManage))
                ->name('index');
            Route::post('/', [ServiceController::class, 'store'])
                ->middleware(Permission::middleware(Permission::ServicesManage))
                ->name('store');
            Route::put('/{service}', [ServiceController::class, 'update'])
                ->middleware(Permission::middleware(Permission::ServicesManage))
                ->name('update');

            // danh sách đơn hàng đang thuê
            Route::prefix('orders')->name('orders.')->group(function () {
                Route::get('/', [ServiceOrderController::class, 'index'])
                    ->middleware(Permission::middleware(Permission::ServiceOrdersView, Permission::ServiceOrdersProcess))
                    ->name('index');
                Route::post('/{id}/accept', [ServiceOrderController::class, 'accept'])
                    ->middleware(Permission::middleware(Permission::ServiceOrdersProcess))
                    ->name('accept');
                Route::get('/receiver', [ServiceOrderController::class, 'getReceiverOrder'])
                    ->middleware(Permission::middleware(Permission::ServiceOrdersView, Permission::ServiceOrdersProcess))
                    ->name('receiver');
                Route::put('/{id}/receiver-complete', [ServiceOrderController::class, 'updateReceiverOrder'])
                    ->middleware(Permission::middleware(Permission::ServiceOrdersProcess))
                    ->name('receiver.complete');

                Route::put('/{id}/receiver-cancel', [ServiceOrderController::class, 'cancelReceiverOrder'])
                    ->middleware(Permission::middleware(Permission::ServiceOrdersProcess))
                    ->name('receiver.cancel');
            });
        });

        // tạo fields
        Route::prefix('fields')->name('fields.')->group(function () {
            Route::get('/', [FieldController::class, 'index'])
                ->middleware(Permission::middleware(Permission::FieldsView, Permission::FieldsManage))
                ->name('index');
            Route::post('/', [FieldController::class, 'store'])
                ->middleware(Permission::middleware(Permission::FieldsManage))
                ->name('store');
            Route::put('/{field}', [FieldController::class, 'update'])
                ->middleware(Permission::middleware(Permission::FieldsManage))
                ->name('update');
            Route::get('getfields', [FieldController::class, 'fields'])
                ->middleware(Permission::middleware(Permission::FieldsView, Permission::FieldsManage))
                ->name('list');
        });

        // gánh fields cho dịch vụ
        Route::prefix('service-fields')->name('service-field.')->group(function () {
            Route::get('/', [ServiceFieldController::class, 'index'])
                ->middleware(Permission::middleware(Permission::ServiceConfigurationManage))
                ->name('index');
            Route::post('/assign', [ServiceFieldController::class, 'assign'])
                ->middleware(Permission::middleware(Permission::ServiceConfigurationManage))
                ->name('assign');
            Route::delete('/remove', [ServiceFieldController::class, 'remove'])
                ->middleware(Permission::middleware(Permission::ServiceConfigurationManage))
                ->name('remove');
        });

        // gánh dịch vụ cho danh mục
        Route::prefix('category-services')->name('category-services.')->group(function () {
            Route::get('/', [CategoryServiceController::class, 'index'])
                ->middleware(Permission::middleware(Permission::ServiceConfigurationManage))
                ->name('index');
            Route::post('/assign', [CategoryServiceController::class, 'assign'])
                ->middleware(Permission::middleware(Permission::ServiceConfigurationManage))
                ->name('assign');
            Route::delete('/remove', [CategoryServiceController::class, 'remove'])
                ->middleware(Permission::middleware(Permission::ServiceConfigurationManage))
                ->name('remove');
        });

        // gánh dịch vụ cho danh mục
        Route::prefix('category-templates')->name('category-templates.')->group(function () {
            Route::get('/', [CategoryTemplateController::class, 'index'])
                ->middleware(Permission::middleware(Permission::ServiceConfigurationManage))
                ->name('index');
            Route::post('/', [CategoryTemplateController::class, 'storeOrUpdate'])
                ->middleware(Permission::middleware(Permission::ServiceConfigurationManage))
                ->name('store');
            Route::delete('/', [CategoryTemplateController::class, 'destroy'])
                ->middleware(Permission::middleware(Permission::ServiceConfigurationManage))
                ->name('destroy');
        });

        // quản lí lịch sử + cộng tiền user
        Route::prefix('transactions')->name('transactions.')->group(function () {
            Route::get('/', [TransactionController::class, 'index'])
                ->middleware(Permission::middleware(Permission::TransactionsView))
                ->name('index');
            Route::post('/add-money', [TransactionController::class, 'addMoney'])
                ->middleware(Permission::middleware(Permission::TransactionsAdjust))
                ->name('addMoney');
        });

        Route::prefix('deposits')->name('deposits.')->controller(DepositController::class)->group(function (): void {
            Route::get('/', 'index')
                ->middleware(Permission::middleware(Permission::DepositsView))
                ->name('index');
            Route::post('/card-types', 'storeCardType')
                ->middleware(Permission::middleware(Permission::DepositsManageCardTypes))
                ->name('card-types.store');
            Route::put('/card-types/{cardType}', 'updateCardType')
                ->middleware(Permission::middleware(Permission::DepositsManageCardTypes))
                ->name('card-types.update');
        });

        // rút tuền
        Route::prefix('withdrawals')->name('withdrawals.')->group(function () {
            Route::get('/', [WithdrawalRequestController::class, 'index'])
                ->middleware(Permission::middleware(Permission::WithdrawalsView, Permission::WithdrawalsProcess))
                ->name('index');
            Route::post('/', [WithdrawalRequestController::class, 'store'])
                ->middleware(Permission::middleware(Permission::WithdrawalsCreate))
                ->name('store');
            Route::post('/{withdrawal}/approve', [WithdrawalRequestController::class, 'approve'])
                ->middleware(Permission::middleware(Permission::WithdrawalsProcess))
                ->name('approve');
            Route::post('/{withdrawal}/mark-paid', [WithdrawalRequestController::class, 'markPaid'])
                ->middleware(Permission::middleware(Permission::WithdrawalsProcess))
                ->name('markPaid');
            Route::post('/{withdrawal}/reject', [WithdrawalRequestController::class, 'reject'])
                ->middleware(Permission::middleware(Permission::WithdrawalsProcess))
                ->name('reject');
        });

        // nạp Carot qua API ngoài
        Route::prefix('carot-recharges')->name('carot-recharges.')->group(function () {
            Route::get('/', [CarotRechargeController::class, 'index'])
                ->middleware(Permission::middleware(Permission::CarotRechargesView))
                ->name('index');
        });

        // Random Box routes
        Route::prefix('randombox')->name('randombox.')->group(function () {
            Route::get('/', [RandomBoxController::class, 'index'])
                ->middleware(Permission::middleware(Permission::RandomBoxesView, Permission::RandomBoxesManage))
                ->name('index');
            Route::post('/', [RandomBoxController::class, 'store'])
                ->middleware(Permission::middleware(Permission::RandomBoxesManage))
                ->name('store');
            Route::put('/{randomBox}', [RandomBoxController::class, 'update'])
                ->middleware(Permission::middleware(Permission::RandomBoxesManage))
                ->name('update');
            Route::delete('/{randomBox}', [RandomBoxController::class, 'destroy'])
                ->middleware(Permission::middleware(Permission::RandomBoxesManage))
                ->name('destroy');
            Route::patch('/{randomBox}/restore', [RandomBoxController::class, 'restore'])
                ->middleware(Permission::middleware(Permission::RandomBoxesManage))
                ->name('restore');
        });

        // random
        Route::prefix('random-nicks')->name('randomnicks.')->group(function () {
            Route::get('/', [RandomNickController::class, 'index'])
                ->middleware(Permission::middleware(Permission::RandomNicksView, Permission::RandomNicksManage))
                ->name('index');
            Route::post('/', [RandomNickController::class, 'store'])
                ->middleware(Permission::middleware(Permission::RandomNicksManage))
                ->name('store');
            Route::post('/bulk', [RandomNickController::class, 'bulkStore'])
                ->middleware(Permission::middleware(Permission::RandomNicksManage))
                ->name('bulk-store');
            Route::put('/{randomNick}', [RandomNickController::class, 'update'])
                ->middleware(Permission::middleware(Permission::RandomNicksManage))
                ->name('update');
            Route::delete('/{randomNick}', [RandomNickController::class, 'destroy'])
                ->middleware(Permission::middleware(Permission::RandomNicksManage))
                ->name('destroy');
            Route::patch('/{randomNick}/restore', [RandomNickController::class, 'restore'])
                ->middleware(Permission::middleware(Permission::RandomNicksManage))
                ->name('restore');
            Route::patch('/{randomNick}/status', [RandomNickController::class, 'changeStatus'])
                ->middleware(Permission::middleware(Permission::RandomNicksManage))
                ->name('change-status');
        });

        Route::resource('spins', SpinController::class)
            ->only(['create', 'store', 'edit', 'update', 'destroy'])
            ->middleware(Permission::middleware(Permission::SpinsManage));
        Route::resource('spins', SpinController::class)
            ->only(['index', 'show'])
            ->middleware(Permission::middleware(Permission::SpinsView, Permission::SpinsManage));
        Route::post('spins/update-order', [SpinController::class, 'updateOrder'])
            ->middleware(Permission::middleware(Permission::SpinsManage))
            ->name('spins.update-order');

        Route::prefix('spins/{spin}')->name('spins.')->group(function () {
            Route::middleware(Permission::middleware(Permission::SpinsManage))->group(function (): void {
                Route::get('rewards', [SpinRewardController::class, 'index'])->name('rewards.index');
                Route::post('rewards', [SpinRewardController::class, 'store'])->name('rewards.store');
                Route::get('rewards/{reward}/edit', [SpinRewardController::class, 'edit'])->name('rewards.edit');
                Route::put('rewards/{reward}', [SpinRewardController::class, 'update'])->name('rewards.update');
                Route::delete('rewards/{reward}', [SpinRewardController::class, 'destroy'])->name('rewards.destroy');
            });
        });

        // Spin Results
        Route::get('spin-results', [SpinResultController::class, 'index'])
            ->middleware(Permission::middleware(Permission::SpinsView, Permission::SpinsManage))
            ->name('spin-results.index');
        Route::get('spin-results/{result}', [SpinResultController::class, 'show'])
            ->middleware(Permission::middleware(Permission::SpinsView, Permission::SpinsManage))
            ->name('spin-results.show');

        Route::resource('spin-tickets', SpinTicketController::class)
            ->only(['create', 'store', 'edit', 'update', 'destroy'])
            ->middleware(Permission::middleware(Permission::SpinTicketsManage));
        Route::resource('spin-tickets', SpinTicketController::class)
            ->only(['index'])
            ->middleware(Permission::middleware(Permission::SpinTicketsView, Permission::SpinTicketsManage));
        Route::post('spin-tickets/bulk-grant', [SpinTicketController::class, 'bulkGrant'])
            ->middleware(Permission::middleware(Permission::SpinTicketsManage))
            ->name('spin-tickets.bulk-grant');

        // Route bổ sung từ Vangtudong mà backend chưa có.
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware(Permission::middleware(Permission::TradingDashboardView))
            ->name('dashboard');

        Route::prefix('servers')->name('servers.')->controller(ServerController::class)->group(function (): void {
            Route::get('/', 'index')
                ->middleware(Permission::middleware(Permission::ServersView, Permission::ServersManage))
                ->name('index');
            Route::post('/', 'store')
                ->middleware(Permission::middleware(Permission::ServersManage))
                ->name('store');
            Route::put('/{server}', 'update')
                ->middleware(Permission::middleware(Permission::ServersManage))
                ->name('update');
        });
        Route::prefix('server-game-logins')->name('server-game-logins.')->controller(ServerGameLoginController::class)->group(function (): void {
            Route::get('/', 'index')
                ->middleware(Permission::middleware(Permission::ServerGameLoginsView, Permission::ServerGameLoginsManage))
                ->name('index');
            Route::post('/', 'store')
                ->middleware(Permission::middleware(Permission::ServerGameLoginsManage))
                ->name('store');
            Route::put('/{server}', 'update')
                ->middleware(Permission::middleware(Permission::ServerGameLoginsManage))
                ->name('update');
            Route::delete('/{server}', 'destroy')
                ->middleware(Permission::middleware(Permission::ServerGameLoginsManage))
                ->name('destroy');
        });

        Route::prefix('bots')->name('bots.')->controller(BotController::class)->group(function (): void {
            Route::get('/', 'index')
                ->middleware(Permission::middleware(Permission::BotsView, Permission::BotsManage))
                ->name('index');
            Route::post('/', 'store')
                ->middleware(Permission::middleware(Permission::BotsManage))
                ->name('store');
            Route::post('/bulk-activate', 'bulkActivate')
                ->middleware(Permission::middleware(Permission::BotsManage))
                ->name('bulk-activate');
            Route::post('/bulk-deactivate', 'bulkDeactivate')
                ->middleware(Permission::middleware(Permission::BotsManage))
                ->name('bulk-deactivate');
            Route::post('/bulk-delete', 'bulkDelete')
                ->middleware(Permission::middleware(Permission::BotsManage))
                ->name('bulk-delete');
            Route::get('/{bot}', 'show')
                ->middleware(Permission::middleware(Permission::BotsView, Permission::BotsManage))
                ->name('show');
            Route::put('/{bot}', 'update')
                ->middleware(Permission::middleware(Permission::BotsManage))
                ->name('update');
            Route::delete('/{bot}', 'destroy')
                ->middleware(Permission::middleware(Permission::BotsManage))
                ->name('destroy');
            Route::patch('/{bot}/toggle-status', 'toggleStatus')
                ->middleware(Permission::middleware(Permission::BotsManage))
                ->name('toggle-status');
        });

        Route::prefix('gold-prices')->name('gold-prices.')->controller(GoldPriceController::class)->group(function (): void {
            Route::get('/', 'index')
                ->middleware(Permission::middleware(Permission::GoldPricesView, Permission::GoldPricesManage))
                ->name('index');
            Route::post('/', 'store')
                ->middleware(Permission::middleware(Permission::GoldPricesManage))
                ->name('store');
            Route::put('/{goldprice}', 'update')
                ->middleware(Permission::middleware(Permission::GoldPricesManage))
                ->name('update');
            Route::delete('/{goldprice}', 'destroy')
                ->middleware(Permission::middleware(Permission::GoldPricesManage))
                ->name('destroy');
        });

        Route::prefix('imports')->name('imports.')->controller(ImportController::class)->group(function (): void {
            Route::get('/', 'index')
                ->middleware(Permission::middleware(Permission::ImportsView))
                ->name('index');
            Route::post('/bulk-update-status', 'bulkUpdateStatus')
                ->middleware(Permission::middleware(Permission::GoldOrdersProcess))
                ->name('bulk-update-status');
            Route::get('/{order}', 'show')
                ->middleware(Permission::middleware(Permission::ImportsView))
                ->name('show');
            Route::patch('/{order}/complete', 'complete')
                ->middleware(Permission::middleware(Permission::GoldOrdersProcess))
                ->name('complete');
            Route::patch('/{order}/process', 'process')
                ->middleware(Permission::middleware(Permission::GoldOrdersProcess))
                ->name('process');
            Route::post('/{order}/cancel', 'cancel')
                ->middleware(Permission::middleware(Permission::GoldOrdersProcess))
                ->name('cancel');
            Route::put('/{order}/status', 'updateStatus')
                ->middleware(Permission::middleware(Permission::GoldOrdersProcess))
                ->name('update-status');
        });
        Route::prefix('orders')->name('orders.')->group(function (): void {
            Route::get('/', [OrderController::class, 'index'])
                ->middleware(Permission::middleware(Permission::GoldOrdersView, Permission::GoldOrdersProcess))
                ->name('index');
            Route::post('/bulk-update-status', [OrderController::class, 'bulkUpdateStatus'])
                ->middleware(Permission::middleware(Permission::GoldOrdersProcess))
                ->name('bulk-update-status');
            Route::get('/{order}', [OrderController::class, 'show'])
                ->middleware(Permission::middleware(Permission::GoldOrdersView, Permission::GoldOrdersProcess))
                ->name('show');
            Route::put('/{order}/status', [OrderController::class, 'updateStatus'])
                ->middleware(Permission::middleware(Permission::GoldOrdersProcess))
                ->name('update-status');
        });

        Route::prefix('gem-bots')->name('gem-bots.')->controller(GemBotController::class)->group(function (): void {
            Route::get('/', 'index')
                ->middleware(Permission::middleware(Permission::GemBotsView, Permission::GemBotsManage))
                ->name('index');
            Route::get('/create', 'create')
                ->middleware(Permission::middleware(Permission::GemBotsManage))
                ->name('create');
            Route::post('/', 'store')
                ->middleware(Permission::middleware(Permission::GemBotsManage))
                ->name('store');
            Route::post('/sync-gems', 'syncGems')
                ->middleware(Permission::middleware(Permission::GemBotsManage))
                ->name('sync-gems');
            Route::get('/{gemBot}', 'show')
                ->middleware(Permission::middleware(Permission::GemBotsView, Permission::GemBotsManage))
                ->name('show');
            Route::get('/{gemBot}/edit', 'edit')
                ->middleware(Permission::middleware(Permission::GemBotsManage))
                ->name('edit');
            Route::put('/{gemBot}', 'update')
                ->middleware(Permission::middleware(Permission::GemBotsManage))
                ->name('update');
            Route::delete('/{gemBot}', 'destroy')
                ->middleware(Permission::middleware(Permission::GemBotsManage))
                ->name('destroy');
            Route::patch('/{gemBot}/gem-quantity', 'updateGemQuantity')
                ->middleware(Permission::middleware(Permission::GemBotsManage))
                ->name('update-gem-quantity');
            Route::patch('/{gemBot}/toggle-status', 'toggleStatus')
                ->middleware(Permission::middleware(Permission::GemBotsManage))
                ->name('toggle-status');
        });

        Route::prefix('gem-prices')->name('gem-prices.')->controller(GemPriceController::class)->group(function (): void {
            Route::get('/', 'index')
                ->middleware(Permission::middleware(Permission::GemPricesView, Permission::GemPricesManage))
                ->name('index');
            Route::post('/', 'store')
                ->middleware(Permission::middleware(Permission::GemPricesManage))
                ->name('store');
            Route::get('/api/current-prices', 'currentMultipliers')
                ->middleware(Permission::middleware(Permission::GemPricesView, Permission::GemPricesManage))
                ->name('current-prices');
            Route::post('/api/calculate', 'calculateGems')
                ->middleware(Permission::middleware(Permission::GemPricesView, Permission::GemPricesManage))
                ->name('calculate');
            Route::post('/bulk-update', 'bulkUpdate')
                ->middleware(Permission::middleware(Permission::GemPricesManage))
                ->name('bulk-update');
            Route::put('/{gemPrice}', 'update')
                ->middleware(Permission::middleware(Permission::GemPricesManage))
                ->name('update');
            Route::delete('/{gemPrice}', 'destroy')
                ->middleware(Permission::middleware(Permission::GemPricesManage))
                ->name('destroy');
            Route::patch('/{gemPrice}/toggle-status', 'toggleStatus')
                ->middleware(Permission::middleware(Permission::GemPricesManage))
                ->name('toggle-status');
        });

        Route::prefix('gem-orders')->name('gem-orders.')->controller(GemOrderController::class)->group(function (): void {
            Route::get('/', 'index')
                ->middleware(Permission::middleware(Permission::GemOrdersView, Permission::GemOrdersProcess))
                ->name('index');
            Route::get('/export/excel', 'export')
                ->middleware(Permission::middleware(Permission::GemOrdersView, Permission::GemOrdersProcess))
                ->name('export');
            Route::get('/api/statistics', 'statistics')
                ->middleware(Permission::middleware(Permission::GemOrdersView, Permission::GemOrdersProcess))
                ->name('statistics');
            Route::post('/bulk-update-status', 'bulkUpdateStatus')
                ->middleware(Permission::middleware(Permission::GemOrdersProcess))
                ->name('bulk-update-status');
            Route::get('/{order}', 'show')
                ->middleware(Permission::middleware(Permission::GemOrdersView, Permission::GemOrdersProcess))
                ->name('show');
            Route::patch('/{order}/status', 'updateStatus')
                ->middleware(Permission::middleware(Permission::GemOrdersProcess))
                ->name('update-status');
            Route::post('/{order}/refund', 'refund')
                ->middleware(Permission::middleware(Permission::GemOrdersProcess))
                ->name('refund');
        });

        Route::get('/bot-history', [BotHistoryController::class, 'index'])
            ->middleware(Permission::middleware(Permission::BotHistoriesView))
            ->name('bot-history.index');
        Route::get('/bots/{bot}/history-quick-history', [BotHistoryController::class, 'quick'])
            ->middleware(Permission::middleware(Permission::BotHistoriesView))
            ->name('bots.history-quick-history');
        Route::get('/gem-bots/{gemBot}/history-quick-history', [BotHistoryController::class, 'quickGem'])
            ->middleware(Permission::middleware(Permission::BotHistoriesView))
            ->name('gem-bots.history-quick-history');
    });

Route::middleware(['guest', 'throttle:10,1'])->group(function (): void {
    Route::get('/auth/google/redirect', [SocialAuthController::class, 'redirect'])
        ->defaults('provider', 'google')
        ->name('social.google.redirect');
    Route::get('/auth/google/callback', [SocialAuthController::class, 'callback'])
        ->defaults('provider', 'google')
        ->name('social.google.callback');
});

require __DIR__.'/auth.php';
