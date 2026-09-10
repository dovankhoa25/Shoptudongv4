<?php

namespace App\Providers;

use App\Listeners\RecordAccessTokenCreated;
use App\Listeners\RecordAccessTokenRevoked;
use App\Models\AtmTopup;
use App\Models\Card;
use App\Models\CarotRecharge;
use App\Models\GemTransaction;
use App\Models\GoldTransaction;
use App\Models\Import;
use App\Models\NickOrder;
use App\Models\Order;
use App\Models\RandomOrder;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use App\Models\WithdrawalRequest;
use App\Observers\AdminRealtimeObserver;
use App\Services\Chat\ChatRealtimeChannel;
use DateInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Events\AccessTokenRevoked;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChatRealtimeChannel::class);
        $this->app->singleton(\App\Services\AdminLive\Updates::class);
    }

    // public function boot(): void
    // {

    //     Vite::prefetch(concurrency: 3);
    // }
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        \App\Models\User::observe(\App\Observers\UserBalanceObserver::class);
        Event::listen(\App\Events\AdminEvent::class,fn($event)=>app(\App\Services\AdminLive\Updates::class)->changed($event->resource));
        Event::listen(\App\Events\NroShopUpdated::class,function($event){foreach($event->adminResources as $resource)app(\App\Services\AdminLive\Updates::class)->changed($resource);});
        Event::listen(\App\Events\UserEvent::class,function($event){if($event->type==='update_balance')app(\App\Services\AdminLive\Updates::class)->changed('user_balance:'.$event->userId);});
        foreach([\App\Events\ChatInboxUpdated::class,\App\Events\ChatMessageSent::class,\App\Events\ChatReadUpdated::class] as $event) Event::listen($event,fn()=>app(\App\Services\AdminLive\Updates::class)->changed('chat'));

        Relation::morphMap([
            'service_order' => ServiceOrder::class,
            'nick_order' => NickOrder::class,
            'gold_transaction' => GoldTransaction::class,
            'gem_transaction' => GemTransaction::class,
        ]);

        foreach ([
            GoldTransaction::class,
            GemTransaction::class,
            ServiceOrder::class,
            NickOrder::class,
            RandomOrder::class,
            WithdrawalRequest::class,
            Card::class,
            AtmTopup::class,
            CarotRecharge::class,
            Transaction::class,
            Order::class,
            Import::class,
        ] as $model) {
            $model::observe(AdminRealtimeObserver::class);
        }

        RateLimiter::for('balance-deposit', fn (Request $request) => Limit::perMinute(10)
            ->by('balance-deposit:'.($request->user()?->id ?? $request->ip()))
        );
        RateLimiter::for('financial-webhook', fn (Request $request) => Limit::perMinute(180)
            ->by('financial-webhook:'.$request->ip())
        );
        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute(240)
            ->by('chat:'.($request->user()?->id ?? $request->ip()))
        );

        Passport::tokensCan([
            'profile:read' => 'Read your profile',
            'profile:write' => 'Update your profile',
            'sessions:read' => 'View your active sessions',
            'sessions:revoke' => 'Revoke your active sessions',
            'balance:deposit' => 'Submit balance deposit requests',
            'chat:read' => 'Read your support conversations',
            'chat:write' => 'Send messages and update read state',
            'chat:manage' => 'Assign and manage support conversations',
            'oauth-clients:manage' => 'Manage OAuth applications',
        ]);
        Passport::defaultScopes(['profile:read']);
        Passport::enablePasswordGrant();
        Passport::tokensExpireIn(new DateInterval('PT6H'));
        Passport::refreshTokensExpireIn(new DateInterval('P30D'));
        Passport::personalAccessTokensExpireIn(new DateInterval('P30D'));
        Passport::viewPrefix('passport');
        Event::listen(AccessTokenCreated::class, RecordAccessTokenCreated::class);
        Event::listen(AccessTokenRevoked::class, RecordAccessTokenRevoked::class);
    }
}
