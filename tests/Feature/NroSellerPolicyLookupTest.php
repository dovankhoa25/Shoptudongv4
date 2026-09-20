<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NroSellerPolicyLookupTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('admin','web');
        $admin=User::factory()->create(); $admin->assignRole('admin'); return $admin;
    }

    public function test_admin_can_find_and_configure_user_without_game_accounts(): void
    {
        $seller=User::factory()->create(['username'=>'ctvtestlookup']);
        $this->actingAs($this->admin(),'web');
        foreach ([$seller->username,(string)$seller->id,'#'.$seller->id] as $q) {
            $this->getJson('/admin/nro-shop/seller-policy?'.http_build_query(['q'=>$q]))
                ->assertOk()->assertJsonPath('userId',$seller->id)->assertJsonPath('username','ctvtestlookup')->assertJsonPath('sellingEnabled',true);
        }
        $this->patchJson('/admin/nro-shop/sellers/'.$seller->id.'/policy',[
            'sellingEnabled'=>false,'allowIds'=>null,'denyIds'=>[223],
        ])->assertOk()->assertJsonPath('sellingEnabled',false)->assertJsonPath('denyIds.0',223);
        $this->assertDatabaseHas('nro_seller_policies',['user_id'=>$seller->id,'selling_enabled'=>false]);
        $this->assertDatabaseCount('nro_accounts',0);
        $this->getJson('/admin/nro-shop/seller-policy?q=ctvtestlookup')->assertOk()->assertJsonPath('sellingEnabled',false);
    }

    public function test_lookup_is_exact_and_supports_numeric_username_explicitly(): void
    {
        $seller=User::factory()->create(['username'=>'999999999']);
        $this->actingAs($this->admin(),'web');
        $this->getJson('/admin/nro-shop/seller-policy?q=@999999999')->assertOk()->assertJsonPath('userId',$seller->id);
        $this->getJson('/admin/nro-shop/seller-policy?q=999999999')->assertNotFound();
        $this->getJson('/admin/nro-shop/seller-policy?q=@999')->assertNotFound();
        $this->getJson('/admin/nro-shop/seller-policy?q=')->assertUnprocessable();
    }

    public function test_ctv_cannot_read_or_change_other_seller_policy(): void
    {
        Role::findOrCreate('ctv','web');
        $actor=User::factory()->create(); $actor->assignRole('ctv');
        $seller=User::factory()->create();
        $url='/admin/nro-shop/sellers/'.$seller->id.'/policy';
        $body=['sellingEnabled'=>false,'allowIds'=>[],'denyIds'=>[]];
        $this->actingAs($actor,'web')->getJson('/admin/nro-shop/seller-policy?q='.$seller->id)->assertForbidden();
        $this->patchJson($url,$body)->assertForbidden();
        $actor->givePermissionTo('nro-sale-policy.manage');
        $this->getJson('/admin/nro-shop/seller-policy?q='.$seller->id)->assertNotFound();
        $this->patchJson($url,$body)->assertNotFound();
        $this->assertDatabaseCount('nro_seller_policies',0);
    }

    public function test_update_rejects_unknown_item_without_creating_policy(): void
    {
        $seller=User::factory()->create();
        $this->actingAs($this->admin(),'web')->patchJson('/admin/nro-shop/sellers/'.$seller->id.'/policy',[
            'sellingEnabled'=>true,'allowIds'=>[99999],'denyIds'=>[],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('nro_seller_policies',0);
    }
}
