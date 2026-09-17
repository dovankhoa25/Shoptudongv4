<?php
namespace Tests\Feature;
use App\Events\AdminViewPatched;
use App\Models\User;
use App\Services\AdminLive\{Delta,Reader,Updates,Views};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache,DB,Event,Http};
use Spatie\Permission\Models\{Permission,Role};
use Tests\TestCase;

class AdminLiveViewTest extends TestCase {
    use RefreshDatabase;
    protected function setUp(): void {parent::setUp();config(['session.driver'=>'database']);app('session')->forgetDrivers();}
    public function actingAs(\Illuminate\Contracts\Auth\Authenticatable $user,$guard=null) {
        parent::actingAs($user,$guard);$this->withSession([\Illuminate\Support\Facades\Auth::guard('web')->getName()=>$user->getAuthIdentifier()]);$this->withCredentials()->withCookie(config('session.cookie'),app('session')->getId());return $this;
    }
    private function admin(): User {
        $role=Role::findOrCreate('super-admin','web');foreach(\App\Enums\Permission::cases() as $permission)$role->givePermissionTo(Permission::findOrCreate($permission->value,'web'));$user=User::factory()->create(['status'=>User::STATUS_ACTIVE]);$user->assignRole('super-admin');return $user;
    }
    public function test_registration_rejects_unlisted_paths_external_urls_and_unauthorized_views(): void {
        $user=User::factory()->create();$this->actingAs($user);
        $this->postJson('/admin/live-views',['url'=>'https://example.org/admin/users'])->assertUnprocessable();
        $this->postJson('/admin/live-views',['url'=>'/admin/users/export'])->assertUnprocessable();
        $this->postJson('/admin/live-views',['url'=>'/admin/nro-shop'])->assertForbidden();
        $this->assertDatabaseCount('admin_live_views',0);
    }
    public function test_authorized_subscription_uses_exact_filtered_projection_and_owner_only_sync(): void {
        Http::preventStrayRequests();$admin=$this->admin();User::factory()->create(['username'=>'matching-user']);User::factory()->create(['username'=>'another-user']);
        $this->actingAs($admin);
        $registered=$this->postJson('/admin/live-views',['url'=>'/admin/users?search=matching-user'])->assertOk()->json();
        $state=$this->postJson('/admin/live-views/'.$registered['id'].'/sync')->assertOk()->json();
        $this->assertSame(['users'],array_keys($state['data']));
        $this->assertStringContainsString('matching-user',json_encode($state));$this->assertStringNotContainsString('another-user',json_encode($state));
        $this->actingAs(User::factory()->create());
        $this->postJson('/admin/live-views/'.$registered['id'].'/sync')->assertNotFound();
        $this->deleteJson('/admin/live-views/'.$registered['id'])->assertNoContent();
        $this->assertDatabaseHas('admin_live_views',['id'=>$registered['id'],'user_id'=>$admin->id]);
    }
    public function test_nro_summary_does_not_build_account_list_and_restores_request_identity(): void {
        $admin=$this->admin();$this->actingAs($admin);$request=app('request');
        DB::enableQueryLog();$data=app(Reader::class)->read($admin,'/admin/nro-shop','summary');$queries=DB::getQueryLog();DB::disableQueryLog();
        $this->assertSame(['accountStats'],array_keys($data));$this->assertSame($request,app('request'));
        $this->assertFalse(collect($queries)->contains(fn($q)=>str_contains(strtolower($q['query']),'from "nicks"')));
    }
    public function test_changes_publish_private_deltas_only_to_relevant_views_and_deduplicate_state(): void {
        $admin=$this->admin();$this->actingAs($admin);$row=User::factory()->create(['username'=>'patch-target']);
        $view=$this->postJson('/admin/live-views',['url'=>'/admin/users?search=patch-target'])->assertOk()->json('id');
        $this->postJson('/admin/live-views/'.$view.'/sync')->assertOk();
        app(Updates::class)->flush();
        $baselineRevision=(int)DB::table('admin_live_views')->where('id',$view)->value('revision');
        Event::fake([AdminViewPatched::class]);
        DB::table('users')->where('id',$row->id)->update(['balance'=>123]);
        $updates=app(Updates::class);$updates->changed('gem_order');$updates->flush();Event::assertNotDispatched(AdminViewPatched::class);
        $updates->changed('balance_transaction');$updates->flush();
        Event::assertDispatched(AdminViewPatched::class,function($event)use($view,$baselineRevision) {
            $this->assertSame('private-Admin.View.'.$view,$event->broadcastOn()[0]->name);
            $this->assertSame($baselineRevision,$event->frame['base']);$this->assertSame($baselineRevision+1,$event->frame['revision']);
            $this->assertLessThan(8000,strlen(json_encode($event->broadcastWith())));return true;
        });
        $count=Event::dispatched(AdminViewPatched::class)->count();$updates->changed('balance_transaction');$updates->flush();
        $this->assertSame($count,Event::dispatched(AdminViewPatched::class)->count());
    }
    public function test_revoked_permission_stops_publication_and_closed_view_receives_nothing(): void {
        $admin=$this->admin();$this->actingAs($admin);
        $id=$this->postJson('/admin/live-views',['url'=>'/admin/users'])->assertOk()->json('id');
        $this->postJson('/admin/live-views/'.$id.'/sync')->assertOk();$admin->removeRole('super-admin');
        Event::fake([AdminViewPatched::class]);$updates=app(Updates::class);$updates->changed('user');$updates->flush();
        Event::assertDispatched(AdminViewPatched::class,fn($event)=>($event->frame['revoked'] ?? false)===true);
        $this->assertDatabaseMissing('admin_live_views',['id'=>$id]);
    }
    public function test_row_delta_inserts_removes_reorders_and_does_not_repeat_unchanged_rows(): void {
        $old=['data'=>[['id'=>1,'status'=>'pending'],['id'=>2,'status'=>'pending']],'total'=>2];
        $new=['data'=>[['id'=>3,'status'=>'pending'],['id'=>1,'status'=>'pending']],'total'=>3];
        $ops=Delta::between($old,$new);$this->assertSame('rows',$ops[0]['op']);$this->assertSame([3,1],$ops[0]['order']);
        $this->assertSame([['id'=>3,'status'=>'pending']],$ops[0]['rows']);$this->assertSame('total',$ops[1]['path'][0]);
    }

    public function test_all_registered_list_projections_render_without_http_requests(): void {
        Http::preventStrayRequests();$admin=$this->admin();$this->actingAs($admin);
        $paths=['/admin/orders','/admin/imports','/admin/gem-orders','/admin/services/orders','/admin/services/orders/receiver',
            '/admin/games/accounts/history','/admin/withdrawals','/admin/cards','/admin/deposits','/admin/carot-recharges',
            '/admin/transactions','/admin/users','/admin/dashboard','/admin/analytics','/admin/nro-shop',
            '/admin/nro-shop/status','/admin/nro-shop/listings','/admin/nro-shop/orders','/admin/nro-shop/jobs',
            '/admin/nro-shop/worker-keys','/admin/chat/conversations','/admin/live-balance'];
        foreach($paths as $path) {
            $data=app(Reader::class)->read($admin,$path);$config=Views::resolve($path);
            $this->assertNotEmpty($data,$path);
            if($config['props']!==null)$this->assertSame([],array_diff(array_keys($data),$config['props']),$path);
        }
    }
    public function test_ctv_projection_cannot_include_another_sellers_accounts_or_passwords(): void {
        Role::findOrCreate('ctv','web');foreach(['nicks.manage','nro-accounts.manage'] as $permission)Permission::findOrCreate($permission,'web');
        $seller=User::factory()->create();$seller->assignRole('ctv');$seller->givePermissionTo(['nicks.manage','nro-accounts.manage']);
        $other=User::factory()->create();
        foreach([$seller,$other] as $owner) \App\Models\NroAccount::create(['user_id'=>$owner->id,'account_name'=>'account-owner-'.$owner->id,'game_password'=>'never-broadcast-password','server'=>'vt1','server_index'=>0,'usage_type'=>'warehouse','status'=>'active']);
        $data=app(Reader::class)->read($seller,'/admin/nro-shop');
        $this->assertStringContainsString('account-owner-'.$seller->id,json_encode($data));
        $this->assertStringNotContainsString('account-owner-'.$other->id,json_encode($data));
        $this->assertStringNotContainsString('never-broadcast-password',json_encode($data));
    }
    public function test_delivery_progress_does_not_requery_accounts_or_summary(): void {
        $admin=$this->admin();$this->actingAs($admin);
        $this->postJson('/admin/live-views',['url'=>'/admin/nro-shop','mode'=>'summary'])->assertOk();
        app(Updates::class)->flush();DB::enableQueryLog();DB::flushQueryLog();
        app(Updates::class)->changed('nro:orders');app(Updates::class)->flush();
        $queries=DB::getQueryLog();DB::disableQueryLog();
        $this->assertFalse(collect($queries)->contains(fn($q)=>str_contains(strtolower($q['query']),'nro_accounts')));
    }
    public function test_balance_live_view_is_private_and_updates_only_its_owner(): void {
        $admin=$this->admin();$other=$this->admin();$this->actingAs($admin);
        $id=$this->postJson('/admin/live-views',['url'=>'/admin/live-balance'])->assertOk()->json('id');
        $this->postJson('/admin/live-views/'.$id.'/sync')->assertOk();app(Updates::class)->flush();
        $this->assertDatabaseHas('admin_live_views',['id'=>$id,'resources'=>json_encode(['user_balance:'.$admin->id])]);
        Event::fake([AdminViewPatched::class]);DB::table('users')->where('id',$admin->id)->update(['balance'=>321]);
        app(Updates::class)->changed('user_balance:'.$other->id);app(Updates::class)->flush();Event::assertNotDispatched(AdminViewPatched::class);
        app(Updates::class)->changed('user_balance:'.$admin->id);app(Updates::class)->flush();Event::assertDispatched(AdminViewPatched::class);
    }

    public function test_logout_or_session_revocation_stops_an_existing_subscription(): void {
        $admin=$this->admin();$this->actingAs($admin);
        $id=$this->postJson('/admin/live-views',['url'=>'/admin/users'])->assertOk()->json('id');
        $this->postJson('/admin/live-views/'.$id.'/sync')->assertOk();app(Updates::class)->flush();
        DB::table('chat_realtime_sessions')->where('user_id',$admin->id)->update(['revoked_at'=>now()]);
        Event::fake([AdminViewPatched::class]);app(Updates::class)->changed('user');app(Updates::class)->flush();
        Event::assertDispatched(AdminViewPatched::class,fn($event)=>($event->frame['revoked'] ?? false)===true);
        $this->assertDatabaseMissing('admin_live_views',['id'=>$id]);
    }

    public function test_identical_tabs_share_a_read_but_different_filters_do_not(): void {
        $admin=$this->admin();$this->actingAs($admin);
        foreach(['/admin/users?search=one','/admin/users?search=one','/admin/users?search=two'] as $url) {
            $this->postJson('/admin/live-views',['url'=>$url])->assertOk();
        }
        app(Updates::class)->flush();
        Event::fake([AdminViewPatched::class]);
        $reader=$this->mock(Reader::class);
        $reader->shouldReceive('read')->once()->withArgs(fn($user,$url,$mode)=>$user->id===$admin->id && $url==='/admin/users?search=one' && $mode==='page')->andReturn(['users'=>['data'=>[['id'=>1]]]]);
        $reader->shouldReceive('read')->once()->withArgs(fn($user,$url,$mode)=>$user->id===$admin->id && $url==='/admin/users?search=two' && $mode==='page')->andReturn(['users'=>['data'=>[['id'=>2]]]]);
        app(Updates::class)->changed('user');app(Updates::class)->flush();
        Event::assertDispatchedTimes(AdminViewPatched::class,3);
    }

    public function test_cache_eviction_keeps_revisions_increasing_and_can_recover(): void {
        $admin=$this->admin();$this->actingAs($admin);
        $id=$this->postJson('/admin/live-views',['url'=>'/admin/users'])->assertOk()->json('id');
        $this->postJson('/admin/live-views/'.$id.'/sync')->assertOk();
        $second=$this->postJson('/admin/live-views/'.$id.'/sync')->assertOk()->json('revision');
        Cache::forget('admin-live:state:'.$id);Event::fake([AdminViewPatched::class]);
        app(Updates::class)->changed('user');app(Updates::class)->flush();
        Event::assertDispatched(AdminViewPatched::class,fn($event)=>$event->frame['base']===0 && $event->frame['revision']>$second);
        $this->assertGreaterThan($second,$this->postJson('/admin/live-views/'.$id.'/sync')->assertOk()->json('revision'));
    }

    public function test_nro_order_read_and_live_view_return_the_same_authorized_order(): void {
        $user=User::factory()->create(['status'=>User::STATUS_ACTIVE]);
        $user->givePermissionTo(Permission::findOrCreate('item-orders.view','web'));
        $this->actingAs($user);
        $account=\App\Models\NroAccount::create(['user_id'=>$user->id,'account_name'=>'live-test','game_password'=>'test-only','server'=>'vt1','server_index'=>0,'usage_type'=>'warehouse','status'=>'active']);
        $listing=DB::table('item_listings')->insertGetId(['user_id'=>$user->id,'account_id'=>$account->id,'title'=>'Test listing','price'=>100]);
        $order=DB::table('item_orders')->insertGetId(['buyer_id'=>$user->id,'seller_id'=>$user->id,'account_id'=>$account->id,'listing_id'=>$listing,'request_key'=>'live-test-order','recipient_name'=>'test','server_index'=>0,'price'=>100,'title'=>'Test order','status'=>'completed','created_at'=>now(),'updated_at'=>now()]);
        $this->getJson('/admin/nro-shop/orders?page=1')->assertOk()->assertJsonPath('data.0.id',$order);
        $view=$this->postJson('/admin/live-views',['url'=>'/admin/nro-shop/orders?page=1','mode'=>'page'])->assertOk()->json('id');
        $this->postJson('/admin/live-views/'.$view.'/sync')->assertOk()->assertJsonPath('data.data.0.id',$order);
    }

    public function test_ordinary_order_read_does_not_bypass_missing_permissions(): void {
        config(['app.debug'=>false]);
        $this->actingAs(User::factory()->create(['status'=>User::STATUS_ACTIVE]));
        $this->getJson('/admin/nro-shop/orders?page=1')->assertForbidden()->assertHeader('Content-Type','application/json');
        $this->postJson('/admin/live-views',['url'=>'/admin/nro-shop/orders?page=1','mode'=>'page'])
            ->assertForbidden()->assertHeader('Content-Type','application/json');
        $this->assertDatabaseCount('admin_live_views',0);
    }

    public function test_revoked_realtime_credential_does_not_prevent_an_authorized_http_read(): void {
        config(['app.debug'=>false]);
        $user=User::factory()->create(['status'=>User::STATUS_ACTIVE]);
        $user->givePermissionTo(Permission::findOrCreate('item-orders.view','web'));
        $this->actingAs($user);
        $payload=['url'=>'/admin/nro-shop/orders?page=1','mode'=>'page'];
        $this->postJson('/admin/live-views',$payload)->assertOk();
        DB::table('chat_realtime_sessions')->where('user_id',$user->id)->update(['revoked_at'=>now()]);
        $this->postJson('/admin/live-views',$payload)->assertForbidden()->assertHeader('Content-Type','application/json');
        $this->getJson($payload['url'])->assertOk();
    }
}
