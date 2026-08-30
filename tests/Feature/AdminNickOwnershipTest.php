<?php

namespace Tests\Feature;

use App\Enums\Permission as AppPermission;
use App\Models\Category;
use App\Models\GameType;
use App\Models\Nick;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminNickOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);

        Permission::findOrCreate(AppPermission::NicksView->value, 'web');
        Permission::findOrCreate(AppPermission::NicksManage->value, 'web');
        Role::findOrCreate('super-admin', 'web');
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('ctv', 'web');
    }

    public function test_ctv_only_sees_their_own_nicks_and_stats(): void
    {
        [$ctv, $otherUser, $ownNick] = $this->createUsersAndNicks();

        $ctv->assignRole('ctv');
        $ctv->givePermissionTo(AppPermission::NicksView->value);

        $this->actingAs($ctv)
            ->get('/admin/games/accounts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Nicks/Index')
                ->has('nicks.data', 1)
                ->where('nicks.data.0.id', $ownNick->id)
                ->where('nicks.data.0.user.id', $ctv->id)
                ->where('stats.total_nicks', 1)
                ->where('stats.not_sold_nicks', 1));

        $this->actingAs($ctv)
            ->get('/admin/games/accounts?user_id='.$otherUser->id)
            ->assertForbidden();
    }

    public function test_admin_and_super_admin_see_all_nicks(): void
    {
        $this->createUsersAndNicks();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->givePermissionTo(AppPermission::NicksView->value);

        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        foreach ([$admin, $superAdmin] as $actor) {
            $this->actingAs($actor)
                ->get('/admin/games/accounts')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Admin/Nicks/Index')
                    ->has('nicks.data', 2)
                    ->where('stats.total_nicks', 2));
        }
    }

    public function test_ctv_cannot_view_update_or_delete_another_users_nick(): void
    {
        [$ctv, , $ownNick, $otherNick] = $this->createUsersAndNicks();

        $ctv->assignRole('ctv');
        $ctv->givePermissionTo([
            AppPermission::NicksView->value,
            AppPermission::NicksManage->value,
        ]);

        $this->assertTrue($ctv->can('view', $ownNick));
        $this->assertTrue($ctv->can('update', $ownNick));
        $this->assertFalse($ctv->can('view', $otherNick));
        $this->assertFalse($ctv->can('update', $otherNick));
        $this->assertFalse($ctv->can('delete', $otherNick));

        $this->actingAs($ctv)
            ->get('/admin/games/accounts/detail/'.$otherNick->id)
            ->assertNotFound();
    }

    public function test_admin_ownership_scope_does_not_hide_public_shop_nicks(): void
    {
        [$ctv, , $ownNick] = $this->createUsersAndNicks();
        $ctv->assignRole('ctv');

        $this->actingAs($ctv)
            ->get('/api/categories/'.$ownNick->category->slug.'/nicks')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /** @return array{User, User, Nick, Nick} */
    private function createUsersAndNicks(): array
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $gameType = GameType::query()->create(['name' => 'Game test']);
        $category = Category::query()->create([
            'game_type_id' => $gameType->id,
            'name' => 'Danh mục test',
            'template' => 'default',
            'is_public' => true,
            'status' => 'active',
        ]);

        $firstNick = Nick::query()->create([
            'account_name' => 'nick-first',
            'account_password' => 'secret',
            'price' => 100000,
            'listing_type' => 'normal',
            'category_id' => $category->id,
            'user_id' => $firstUser->id,
            'status' => 'not_sold',
        ]);
        $secondNick = Nick::query()->create([
            'account_name' => 'nick-second',
            'account_password' => 'secret',
            'price' => 200000,
            'listing_type' => 'normal',
            'category_id' => $category->id,
            'user_id' => $secondUser->id,
            'status' => 'not_sold',
        ]);

        return [$firstUser, $secondUser, $firstNick, $secondNick];
    }
}
