<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Helpers\AccountEncrypt;
use App\Models\Category;
use App\Models\GameType;
use App\Models\Nick;
use App\Models\NickOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminNickSearchAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate(AppPermission::NicksView->value, 'web');
        Role::findOrCreate('ctv', 'web');
    }

    public function test_ctv_search_cannot_escape_the_seller_constraint(): void
    {
        $viewer = User::factory()->create();
        $otherSeller = User::factory()->create();
        $viewer->assignRole('ctv');
        $viewer->givePermissionTo(AppPermission::NicksView->value);

        $ownOrder = $this->orderFor($viewer);
        $otherOrder = $this->orderFor($otherSeller);

        $this->actingAs($viewer)
            ->get(route('admin.games.accounts.history.index', ['search' => '#'.$otherOrder->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));

        $this->actingAs($viewer)
            ->get(route('admin.games.accounts.history.index', ['search' => 'seller_id:'.$otherSeller->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 0));

        $this->actingAs($viewer)
            ->get(route('admin.games.accounts.history.index', ['search' => '#'.$ownOrder->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 1)
                ->where('orders.data.0.id', $ownOrder->id));
    }

    private function orderFor(User $seller): NickOrder
    {
        $gameType = GameType::query()->create(['name' => 'Game '.$seller->id]);
        $category = Category::query()->create([
            'game_type_id' => $gameType->id,
            'name' => 'Danh mục '.$seller->id,
            'template' => 'default',
            'is_public' => true,
            'status' => 'active',
        ]);
        $nick = Nick::query()->create([
            'category_id' => $category->id,
            'user_id' => $seller->id,
            'account_name' => 'nick-'.$seller->id,
            'account_password' => AccountEncrypt::encrypt('secret'),
            'price' => 100000,
            'listing_type' => 'normal',
            'status' => 'sold',
        ]);
        $buyer = User::factory()->create();

        return NickOrder::query()->create([
            'nick_id' => $nick->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'price' => 100000,
            'status' => 'completed',
        ]);
    }
}
