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
}
