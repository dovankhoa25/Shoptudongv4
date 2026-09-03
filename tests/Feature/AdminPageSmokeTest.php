<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPageSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_static_admin_page_renders(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        Role::findOrCreate('super-admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $pages = [
            '/admin' => 'Admin/Page',
            '/admin/analytics' => 'Admin/Analytics/Index',
            '/admin/bot-history' => 'Admin/BotHistory/Index',
            '/admin/bots' => 'Admin/Bots/Index',
            '/admin/cards' => 'Admin/Cards/Index',
            '/admin/cardtypes' => 'Admin/CardTypes/Index',
            '/admin/carot-recharges' => 'Admin/CarotRecharges/Index',
            '/admin/category-services' => 'Admin/CategoryServices/Index',
            '/admin/category-templates' => 'Admin/CategoryTemplates/Index',
            '/admin/dashboard' => 'Admin/Dashboard',
            '/admin/deposits' => 'Admin/Deposits/Index',
            '/admin/fields' => 'Admin/Field/Index',
            '/admin/frontend-clients' => 'Admin/FrontendClients/Index',
            '/admin/games/accounts' => 'Admin/Nicks/Index',
            '/admin/games/accounts/create' => 'Admin/Nicks/Create',
            '/admin/games/accounts/history' => 'Admin/NickOrders/Index',
            '/admin/games/attributes' => 'Admin/Attributes/Index',
            '/admin/games/categories' => 'Admin/Categories/Index',
            '/admin/games/categories/create' => 'Admin/Categories/Create',
            '/admin/games/category-attributes' => 'Admin/CategoryAttributes/Index',
            '/admin/games/gametypes' => 'Admin/GameTypes/Index',
            '/admin/gem-bots' => 'Admin/GemBots/Index',
            '/admin/gem-bots/create' => 'Admin/GemBots/Create',
            '/admin/gem-orders' => 'Admin/GemOrders/Index',
            '/admin/gem-prices' => 'Admin/GemPrices/Index',
            '/admin/gold-prices' => 'Admin/GoldPrices/Index',
            '/admin/imports' => 'Admin/Imports/Index',
            '/admin/orders' => 'Admin/Orders/Index',
            '/admin/random-nicks' => 'Admin/RandomNicks/Index',
            '/admin/randombox' => 'Admin/RandomBoxes/Index',
            '/admin/roles' => 'Admin/Role/Index',
            '/admin/server-game-logins' => 'Admin/ServerGameLogins/Index',
            '/admin/servers' => 'Admin/Servers/Index',
            '/admin/service-fields' => 'Admin/ServiceFields/Index',
            '/admin/services' => 'Admin/Service/Index',
            '/admin/services/orders' => 'Admin/ServiceOrders/Index',
            '/admin/services/orders/receiver' => 'Admin/ServiceOrders/Receiver',
            '/admin/spin-results' => 'Admin/SpinResults/Index',
            '/admin/spin-tickets' => 'Admin/SpinTickets/Index',
            '/admin/spin-tickets/create' => 'Admin/SpinTickets/Create',
            '/admin/spins' => 'Admin/Spins/Index',
            '/admin/spins/create' => 'Admin/Spins/Create',
            '/admin/transactions' => 'Admin/Transactions/Index',
            '/admin/users' => 'Admin/Users/Index',
            '/admin/users/ctv' => 'Admin/CongTacVien/Index',
            '/admin/withdrawals' => 'Admin/Withdrawals/Index',
        ];

        foreach ($pages as $url => $component) {
            $this->actingAs($admin)
                ->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component($component));
        }
    }

    public function test_every_admin_table_accepts_a_regular_search_term(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        Role::findOrCreate('super-admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $pages = [
            '/admin/bot-history',
            '/admin/bots',
            '/admin/cards',
            '/admin/cardtypes',
            '/admin/carot-recharges',
            '/admin/fields',
            '/admin/frontend-clients',
            '/admin/games/accounts',
            '/admin/games/accounts/history',
            '/admin/games/attributes',
            '/admin/games/categories',
            '/admin/games/gametypes',
            '/admin/gem-bots',
            '/admin/gem-orders',
            '/admin/gem-prices',
            '/admin/gold-prices',
            '/admin/imports',
            '/admin/orders',
            '/admin/random-nicks',
            '/admin/randombox',
            '/admin/roles',
            '/admin/server-game-logins',
            '/admin/servers',
            '/admin/services',
            '/admin/services/orders',
            '/admin/services/orders/receiver',
            '/admin/spin-results',
            '/admin/spin-tickets',
            '/admin/spins',
            '/admin/transactions',
            '/admin/users',
            '/admin/users/ctv',
            '/admin/withdrawals',
        ];

        foreach ($pages as $url) {
            $this->actingAs($admin)
                ->get($url.'?search=search-probe')
                ->assertOk();
        }

        $this->actingAs($admin)
            ->get('/admin/deposits?card_search=search-probe&bank_search=search-probe')
            ->assertOk();
    }

    public function test_every_admin_table_accepts_its_field_search_syntax(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);

        Role::findOrCreate('super-admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $searches = [
            '/admin/bot-history' => 'admin:search-probe',
            '/admin/bots' => 'account:search-probe',
            '/admin/cards' => 'user:search-probe',
            '/admin/cardtypes' => 'telco:search-probe',
            '/admin/carot-recharges' => 'user:search-probe',
            '/admin/fields' => 'key:search-probe',
            '/admin/frontend-clients' => 'domain:search-probe',
            '/admin/games/accounts' => 'user:search-probe',
            '/admin/games/accounts/history' => 'buyer:search-probe',
            '/admin/games/attributes' => 'option:search-probe',
            '/admin/games/categories' => 'template:search-probe',
            '/admin/games/gametypes' => 'slug:search-probe',
            '/admin/gem-bots' => 'map:search-probe',
            '/admin/gem-orders' => 'user:search-probe',
            '/admin/gem-prices' => 'server:search-probe',
            '/admin/gold-prices' => 'server:search-probe',
            '/admin/imports' => 'bot:search-probe',
            '/admin/orders' => 'bot:search-probe',
            '/admin/random-nicks' => 'box:search-probe',
            '/admin/randombox' => 'category_id:999999',
            '/admin/roles' => 'name:search-probe',
            '/admin/server-game-logins' => 'ip:127.0.0.1',
            '/admin/servers' => 'name:search-probe',
            '/admin/services' => 'name:search-probe',
            '/admin/services/orders' => 'service:search-probe',
            '/admin/services/orders/receiver' => 'receiver:search-probe',
            '/admin/spin-results' => 'spin:search-probe',
            '/admin/spin-tickets' => 'user:search-probe',
            '/admin/spins' => 'name:search-probe',
            '/admin/transactions' => 'performer:search-probe',
            '/admin/users' => 'username:search-probe',
            '/admin/users/ctv' => 'email:search-probe',
            '/admin/withdrawals' => 'approver:search-probe',
        ];

        foreach ($searches as $url => $search) {
            $this->actingAs($admin)
                ->get($url.'?'.http_build_query(['search' => $search]))
                ->assertOk();
        }

        $this->actingAs($admin)
            ->get('/admin/deposits?'.http_build_query([
                'card_search' => 'serial:search-probe',
                'bank_search' => 'reference:search-probe',
            ]))
            ->assertOk();
    }
}
