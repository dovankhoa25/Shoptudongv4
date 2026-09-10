<?php
namespace Tests\Feature;

use App\Models\Category;
use App\Events\NroShopUpdated;
use App\Models\GameType;
use App\Models\Nick;
use App\Models\NroAccount;
use App\Models\User;
use App\Services\NroSnapshotService;
use App\Services\NroShopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NroShopWorkflowTest extends TestCase
{
    use RefreshDatabase;
    private string $token;
    protected function setUp(): void
    {
        parent::setUp(); $this->withoutMiddleware(ThrottleRequests::class);
        Permission::findOrCreate('nicks.manage', 'web'); Role::findOrCreate('ctv', 'web'); Role::findOrCreate('admin', 'web');
        $this->token = 'nrow_'.Str::random(64);
        DB::table('nro_worker_keys')->insert(['name' => 'test', 'token_hash' => hash('sha256', $this->token), 'accepts_delivery' => true, 'last_used_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
    public function test_idle_worker_claim_does_not_trigger_website_refresh(): void
    {
        Event::fake([NroShopUpdated::class]);
        $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertSuccessful();
        Event::assertNotDispatched(NroShopUpdated::class);
    }

    public function test_catalog_reads_never_broadcast_a_refresh(): void
    {
        Event::fake([NroShopUpdated::class]);
        $this->getJson('/api/nro-shop/listings')->assertOk();
        Event::assertNotDispatched(NroShopUpdated::class);
    }

    public function test_listing_write_emits_one_public_invalidation_without_private_data(): void
    {
        $seller = $this->seller();
        $account = $this->warehouse($seller);
        Event::fake([NroShopUpdated::class]);
        $this->listing($seller, $account);
        Event::assertDispatchedTimes(NroShopUpdated::class, 1);
        Event::assertDispatched(NroShopUpdated::class, function (NroShopUpdated $event): bool {
            $this->assertSame(['event_id', 'catalog'], array_keys($event->broadcastWith()));
            $this->assertSame(['Nro.Shop', 'private-Nro.Admin'], array_map(fn ($channel) => $channel->name, $event->broadcastOn()));
            return $event->catalog;
        });
    }

    public function test_private_order_signal_does_not_reach_public_catalog(): void
    {
        $event = new NroShopUpdated('event-test', false, [123]);
        $this->assertSame(['private-Nro.Admin', 'private-User.123'], array_map(fn ($channel) => $channel->name, $event->broadcastOn()));
        $this->assertSame(['event_id' => 'event-test', 'catalog' => false], $event->broadcastWith());
    }

    public function test_purchase_signal_targets_the_buyer_and_not_unrelated_users(): void
    {
        $seller = $this->seller();
        $account = $this->warehouse($seller);
        $listingId = $this->listing($seller, $account);
        $buyer = User::factory()->create(['balance' => 1000]);
        User::factory()->create();
        Passport::actingAs($buyer);
        Event::fake([NroShopUpdated::class]);
        $this->postJson('/api/nro-shop/orders', [
            'listingId' => $listingId, 'recipientName' => 'khachgame',
            'serverId' => 10, 'requestKey' => (string) Str::uuid(),
        ])->assertOk();
        Event::assertDispatchedTimes(NroShopUpdated::class, 1);
        Event::assertDispatched(NroShopUpdated::class, fn (NroShopUpdated $event): bool => $event->catalog && $event->buyerIds === [$buyer->id]);
    }

    private function seller(): User
    {
        $user = User::factory()->create(['balance' => 0]); $user->assignRole('ctv'); $user->givePermissionTo(['nicks.manage','nro-accounts.manage','item-listings.manage']); return $user;
    }
    private function payload(int $count = 2, bool $chestComplete = true): array
    {
        return ['schemaVersion' => 1, 'catalogVersion' => '17', 'completeness' => ['bag' => true, 'chest' => $chestComplete],
            'snapshot' => ['capturedAt' => now()->toIso8601String(), 'password' => 'NEVER_PUBLIC', 'character' => ['id' => 10, 'name' => 'botgame', 'gender' => 0, 'power' => 100000, 'password' => 'NEVER_PUBLIC'],
                'equipped' => [], 'chest' => [], 'collectionChest' => [], 'bag' => $count > 0 ? [
                    ['slot' => 0, 'templateId' => 0, 'quantity' => $count, 'options' => [['optionId' => 0, 'param' => 100]]],
                    ['slot' => 1, 'templateId' => 0, 'quantity' => $count, 'options' => [['optionId' => 0, 'param' => 150]]],
                ] : []]];
    }
    private function warehouse(User $seller): NroAccount
    {
        DB::table('servers')->insertOrIgnore(['id' => 10, 'name' => 'sv10', 'name_view' => 'Server test', 'status' => true]);
        if (!DB::table('server_game_login')->where('id', 37)->exists()) DB::table('server_game_login')->insert(['id' => 37, 'name' => 'Login test', 'ip' => '127.0.0.1', 'port' => '14445']);
        $a = NroAccount::create(['user_id' => $seller->id, 'account_name' => 'acc'.$seller->id, 'game_password' => 'secret-pass', 'server' => 'vt1', 'server_index' => 0, 'server_id' => 10, 'server_game_id' => 37, 'usage_type' => 'warehouse', 'status' => 'active']);
        app(NroSnapshotService::class)->ingest($a, $this->payload()); return $a->fresh();
    }
    private function listing(User $seller, NroAccount $a): int
    {
        return $this->actingAs($seller, 'web')->postJson('/admin/nro-shop/accounts/'.$a->id.'/listings', [
            'title' => 'Hai món khác option', 'price' => 200, 'items' => DB::table('nro_inventory_items')->where('account_id', $a->id)->get()->map(fn ($i) => ['id' => $i->id, 'quantity' => 1])->all(),
        ])->assertOk()->json('id');
    }
    public function test_snapshot_splits_options_and_preserves_reserved_on_rescan(): void
    {
        $a = $this->warehouse($this->seller());
        $this->assertDatabaseCount('nro_inventory_items', 2);
        $item = DB::table('nro_inventory_items')->first();
        DB::table('nro_inventory_items')->where('id', $item->id)->update(['reserved' => 1]);
        $snapshot = app(NroSnapshotService::class)->ingest($a, $this->payload(1));
        $this->assertDatabaseHas('nro_inventory_items', ['id' => $item->id, 'quantity' => 1, 'reserved' => 1]);
        $this->assertStringNotContainsString('NEVER_PUBLIC', json_encode($snapshot->data_json));
        $this->assertStringNotContainsString('secret-pass', json_encode($a));
        $this->assertNotEquals('secret-pass', DB::table('nro_accounts')->where('id', $a->id)->value('game_password'));
        app(NroSnapshotService::class)->ingest($a, $this->payload(0, false));
        $this->assertDatabaseHas('nro_inventory_items', ['id' => $item->id, 'quantity' => 1]);
    }
    public function test_task_snapshot_preserves_progress_and_steps_without_arbitrary_private_fields(): void
    {
        $a = $this->warehouse($this->seller()); $payload = $this->payload();
        $payload['snapshot']['currentTask'] = ['id' => 17, 'name' => 'Nhiệm vụ thử', 'detail' => "Dòng 1\nDòng 2", 'currentStep' => 1, 'currentCount' => 3, 'password' => 'NEVER_PUBLIC', 'steps' => [
            ['name' => 'Gặp NPC', 'detail' => 'Đến gặp người hướng dẫn', 'objectiveType' => 0, 'mapId' => 5, 'requiredCount' => -1, 'password' => 'NEVER_PUBLIC'],
            ['name' => 'Đánh quái', 'detail' => 'Hoàn thành mục tiêu', 'objectiveType' => 1, 'mapId' => 7, 'requiredCount' => 10],
        ]];
        $snapshot = app(NroSnapshotService::class)->ingest($a, $payload);
        $task = $snapshot->data_json['currentTask'];
        $this->assertSame(1, $task['currentStep']); $this->assertSame(3, $task['currentCount']);
        $this->assertCount(2, $task['steps']); $this->assertSame(10, $task['steps'][1]['requiredCount']);
        $this->assertSame(7, $task['steps'][1]['mapId']); $this->assertStringNotContainsString('NEVER_PUBLIC', json_encode($task));
        unset($payload['snapshot']['currentTask']['steps']);
        $this->assertSame('Nhiệm vụ thử', app(NroSnapshotService::class)->ingest($a, $payload)->data_json['currentTask']['name']);
        $payload['snapshot']['currentTask'] = null;
        $this->assertNull(app(NroSnapshotService::class)->ingest($a, $payload)->data_json['currentTask']);
    }

    public function test_listing_description_stays_in_admin_and_is_not_returned_to_public_shop(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $id = $this->listing($seller, $a);
        DB::table('item_listings')->where('id', $id)->update(['description' => 'Ghi chú chỉ ở admin']);
        $this->getJson('/api/nro-shop/listings')->assertOk()->assertJsonMissingPath('data.0.description')->assertDontSee('Ghi chú chỉ ở admin');
        $this->getJson('/api/nro-shop/listings/'.$id)->assertOk()->assertJsonMissingPath('data.description');
        $this->actingAs($seller, 'web')->getJson('/admin/nro-shop/accounts/'.$a->id.'/listings')->assertOk()->assertJsonPath('data.0.description', 'Ghi chú chỉ ở admin');
    }

    public function test_public_item_filters_apply_before_pagination_and_do_not_search_private_notes(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller);
        $inventory = DB::table('nro_inventory_items')->where('account_id', $a->id)->orderBy('id')->get();
        foreach ($inventory as $i) {
            $item = json_decode($i->item_json, true); $item['name'] = 'Đá lục bảo';
            DB::table('nro_inventory_items')->where('id', $i->id)->update(['item_json' => json_encode($item)]);
        }
        $ids = [];
        foreach (range(1, 23) as $n) {
            $id = DB::table('item_listings')->insertGetId(['user_id' => $seller->id, 'account_id' => $a->id,
                'title' => 'Tiêu đề nội bộ', 'description' => 'private-search-secret', 'price' => $n * 100, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $ids[] = $id;
            DB::table('item_listing_items')->insert(['listing_id' => $id, 'inventory_item_id' => $inventory[0]->id, 'quantity' => 1]);
            if ($n === 23) DB::table('item_listing_items')->insert(['listing_id' => $id, 'inventory_item_id' => $inventory[1]->id, 'quantity' => 1]);
        }
        $this->getJson('/api/nro-shop/listings?sort=price_asc')->assertOk()->assertJsonPath('total', 23)
            ->assertJsonPath('lastPage', 2)->assertJsonPath('data.0.id', $ids[0])->assertJsonCount(20, 'data');
        $this->getJson('/api/nro-shop/listings?sort=price_asc&page=2')->assertOk()->assertJsonPath('data.0.id', $ids[20]);
        $this->getJson('/api/nro-shop/listings?'.http_build_query(['q' => 'lục bảo', 'server' => 10, 'bundle' => 'single', 'minPrice' => 200, 'maxPrice' => 400, 'sort' => 'price_desc']))
            ->assertOk()->assertJsonPath('total', 3)->assertJsonPath('data.0.id', $ids[3]);
        $this->getJson('/api/nro-shop/listings?bundle=combo&q=0')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ids[22]);
        $this->getJson('/api/nro-shop/listings?q=private-search-secret')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/nro-shop/listings?q=%25')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/nro-shop/listings?server=9999')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/nro-shop/listings?minPrice=500&maxPrice=100')->assertUnprocessable();
        $this->getJson('/api/nro-shop/listings?sort=invalid')->assertUnprocessable();
    }

    public function test_snapshot_retains_skill_icons_for_master_and_disciple(): void
    {
        $a = $this->warehouse($this->seller()); $payload = $this->payload();
        $skill = ['skillId' => 42, 'templateId' => 4, 'iconId' => 555, 'name' => 'Chiêu thử', 'level' => 3, 'password' => 'NEVER_PUBLIC'];
        $payload['snapshot']['skills'] = [$skill];
        $payload['snapshot']['disciple'] = ['exists' => true, 'hasDetails' => true, 'skills' => [$skill]];
        $snapshot = app(NroSnapshotService::class)->ingest($a, $payload);
        $this->assertEquals(555, $snapshot->data_json['skills'][0]['iconId']);
        $this->assertEquals(555, $snapshot->data_json['disciple']['skills'][0]['iconId']);
        $this->assertStringNotContainsString('NEVER_PUBLIC', json_encode($snapshot->data_json));
    }
    public function test_partial_snapshot_blocks_new_bundle_until_inventory_is_complete(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller);
        $itemId = DB::table('nro_inventory_items')->where('account_id', $a->id)->min('id');
        app(NroSnapshotService::class)->ingest($a, $this->payload(2, false));
        $body = ['title' => 'Gói thử', 'price' => 200, 'items' => [['id' => $itemId, 'quantity' => 1]]];
        $this->actingAs($seller)->postJson('/admin/nro-shop/accounts/'.$a->id.'/listings', $body)->assertUnprocessable()
            ->assertJsonPath('message', 'Chưa lấy đủ hành trang và rương. Yêu cầu tool lấy lại dữ liệu trước khi tạo gói đồ.');
        $this->assertDatabaseCount('item_listings', 0);
        $this->assertDatabaseHas('nro_inventory_items', ['id' => $itemId, 'quantity' => 2]);
        app(NroSnapshotService::class)->ingest($a, $this->payload());
        $this->postJson('/admin/nro-shop/accounts/'.$a->id.'/listings', $body)->assertOk();
    }
    public function test_card_summary_includes_disciple_and_can_refresh_old_snapshots(): void
    {
        $a = $this->warehouse($this->seller()); $payload = $this->payload();
        $payload['snapshot']['disciple'] = ['exists' => true, 'hasDetails' => true, 'name' => 'Đệ tử', 'power' => 123456, 'password' => 'NEVER_PUBLIC', 'skills' => []];
        $payload['snapshot']['chest'] = array_map(fn ($slot) => ['slot' => $slot, 'templateId' => 0, 'quantity' => 2, 'options' => []], range(0, 9));
        $snapshot = app(NroSnapshotService::class)->ingest($a, $payload);
        $expected = ['exists' => true, 'hasDetails' => true, 'name' => 'Đệ tử', 'power' => 123456];
        $this->assertEquals($expected, $snapshot->summary_json['disciple']);
        $previews = $snapshot->summary_json['itemPreviews'];
        $this->assertEquals(10, $previews['chest']['count']);
        $this->assertCount(6, $previews['chest']['items']);
        $this->assertEquals(2, $previews['bag']['count']);
        $this->assertEquals(0, $previews['equipped']['count']);
        $this->assertArrayHasKey('iconId', $previews['chest']['items'][0]);
        $this->assertArrayNotHasKey('options', $previews['chest']['items'][0]);
        $raw = $snapshot->data_json; $summary = $snapshot->summary_json; unset($summary['disciple']);
        $snapshot->update(['summary_json' => $summary]);
        $this->artisan('nro:refresh-card-summaries')->assertSuccessful();
        $this->assertEquals($expected, $snapshot->fresh()->summary_json['disciple']);
        $this->assertEquals($raw, $snapshot->fresh()->data_json);
        // The previous deployed format already had disciple, but lacked inventory previews.
        $summary = $snapshot->fresh()->summary_json; unset($summary['itemPreviews']);
        $snapshot->update(['summary_json' => $summary]);
        $this->artisan('nro:refresh-card-summaries')->assertSuccessful();
        $this->assertEquals($previews, $snapshot->fresh()->summary_json['itemPreviews']);
        $this->assertEquals($raw, $snapshot->fresh()->data_json);
        $this->artisan('nro:refresh-card-summaries')->expectsOutput('Updated 0 snapshot card summaries.')->assertSuccessful();
    }
    public function test_ctv_cannot_read_scan_or_publish_another_account(): void
    {
        $a = $this->warehouse($this->seller()); $other = $this->seller();
        $this->actingAs($other)->getJson('/admin/nro-shop/accounts/'.$a->id)->assertNotFound();
        $this->actingAs($other)->postJson('/admin/nro-shop/accounts/'.$a->id.'/scan')->assertNotFound();
        $this->actingAs($other)->postJson('/admin/nro-shop/accounts/'.$a->id.'/listings', [])->assertNotFound();
        $this->getJson('/api/nro-shop/listings')->assertOk()->assertDontSee('secret-pass');
    }

    public function test_sold_account_can_be_registered_again_without_reusing_sale_history(): void
    {
        [$seller, $old, $category, , $snapshot] = $this->nickAttributesFixture();
        $old->update(['server' => 'login37']);
        $oldNickId = $this->actingAs($seller)->postJson('/admin/nro-shop/accounts/'.$old->id.'/nick', ['categoryId' => $category->id, 'price' => 200])->assertOk()->json('id');
        $request = ['username' => ' '.$old->account_name.' ', 'password' => 'new-owner-password', 'serverId' => 10, 'serverGameId' => 37, 'usageType' => 'nick', 'categoryId' => $category->id, 'price' => 300];
        $this->postJson('/admin/nro-shop/accounts', $request)->assertUnprocessable();
        Nick::withoutUserOwnedScope()->findOrFail($oldNickId)->update(['status' => 'sold']);
        $old->update(['status' => 'sold', 'game_password' => null]);

        $newId = $this->postJson('/admin/nro-shop/accounts', $request)->assertOk()->json('id');
        $this->assertNotEquals($old->id, $newId);
        $new = NroAccount::findOrFail($newId);
        $this->assertEquals('active', $new->status);
        $this->assertEquals('new-owner-password', $new->game_password);
        $this->assertNull($new->latest_snapshot_id);
        $this->assertDatabaseHas('nicks', ['id' => $oldNickId, 'status' => 'sold', 'game_account_id' => $old->id, 'snapshot_id' => $snapshot->id]);
        $this->assertEquals('sold', $old->fresh()->status);
        $this->assertNull($old->fresh()->game_password);
        $this->assertDatabaseMissing('nro_inventory_items', ['account_id' => $newId]);
        $this->postJson('/admin/nro-shop/accounts/'.$old->id.'/scan')->assertUnprocessable();
        $this->finishAutoSnapshot();
        $newNickId = Nick::withoutUserOwnedScope()->where('game_account_id', $newId)->firstOrFail()->id;
        $this->assertNotEquals($oldNickId, $newNickId);
        $this->assertDatabaseHas('nicks', ['id' => $newNickId, 'status' => 'not_sold', 'game_account_id' => $newId]);
        $this->actingAs($this->seller())->postJson('/admin/nro-shop/accounts', $request)->assertForbidden();
    }

    public function test_registration_blocks_unsold_inactive_and_trashed_accounts_and_duplicate_server_changes(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller);
        $request = ['username' => $a->account_name, 'password' => 'new-password', 'serverId' => 10, 'serverGameId' => 37, 'usageType' => 'warehouse'];
        $a->update(['status' => 'inactive']);
        $this->actingAs($seller)->postJson('/admin/nro-shop/accounts', $request)->assertUnprocessable();
        $a->delete();
        $this->postJson('/admin/nro-shop/accounts', $request)->assertUnprocessable();
        DB::table('server_game_login')->insert(['id' => 38, 'name' => 'Other server', 'ip' => '127.0.0.1', 'port' => '14446']);
        $newId = $this->postJson('/admin/nro-shop/accounts', [...$request, 'serverGameId' => 38])->assertOk()->json('id');
        $settings = ['server_id' => 10, 'server_game_id' => 37, 'delivery_map' => 1, 'delivery_zone' => 1, 'wait_minutes' => 10];
        $seller->givePermissionTo('nro-settings.manage');
        $this->patchJson('/admin/nro-shop/accounts/'.$newId.'/settings', $settings)->assertUnprocessable();
        $this->assertDatabaseHas('nro_accounts', ['id' => $newId, 'server_game_id' => 38]);
    }
    public function test_bundle_purchase_is_atomic_idempotent_and_cannot_oversell(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $id = $this->listing($seller, $a);
        $buyer = User::factory()->create(['balance' => 1000]); Passport::actingAs($buyer);
        $body = ['listingId' => $id, 'recipientName' => 'khachgame', 'serverId' => 10, 'requestKey' => (string) Str::uuid()];
        $order = $this->postJson('/api/nro-shop/orders', $body)->assertOk()->json('data.id');
        $this->postJson('/api/nro-shop/orders', $body)->assertOk()->assertJsonPath('data.id', $order);
        $this->assertEquals(800, $buyer->fresh()->balance); $this->assertEquals(0, $seller->fresh()->balance);
        $this->assertDatabaseCount('item_order_items', 2); $this->assertDatabaseCount('item_inventory_reservations', 2);
        $this->postJson('/api/nro-shop/orders', [...$body, 'requestKey' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseHas('item_listings', ['id' => $id, 'status' => 'sold']);
        $this->getJson('/api/nro-shop/listings')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/nro-shop/listings/'.$id)->assertNotFound();
        $this->getJson('/api/nro-shop/orders')->assertOk()->assertJsonPath('data.0.id', $order);
        // Another listing may use the remaining physical stock, but cannot oversell it.
        $next = $this->listing($seller, $a); Passport::actingAs($buyer);
        $this->postJson('/api/nro-shop/orders', [...$body, 'listingId' => $next, 'requestKey' => (string) Str::uuid()])->assertOk();
        $this->postJson('/api/nro-shop/orders', [...$body, 'listingId' => $next, 'requestKey' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertEquals(600, $buyer->fresh()->balance);
        $this->assertDatabaseCount('item_orders', 2);
        $this->postJson('/api/nro-shop/orders', [...$body, 'recipientName' => 'different'])->assertUnprocessable();
        $this->postJson('/api/nro-shop/orders', [...$body, 'serverId' => 20, 'requestKey' => (string) Str::uuid()])->assertUnprocessable();
    }
    public function test_listings_allocate_stock_before_purchase_and_pausing_releases_it(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller);
        app(NroSnapshotService::class)->ingest($a, $this->payload(500));
        $item = DB::table('nro_inventory_items')->where('account_id', $a->id)->orderBy('id')->first();
        $url = '/admin/nro-shop/accounts/'.$a->id.'/listings';
        $body = ['title' => 'Đá 300', 'price' => 200, 'items' => [['id' => $item->id, 'quantity' => 300]]];
        $first = $this->actingAs($seller, 'web')->postJson($url, $body)->assertOk()->json('id');
        $this->getJson('/admin/nro-shop/accounts/'.$a->id)->assertOk()->assertJsonPath('inventory.0.selectable', 200)->assertJsonPath('inventory.0.listed', 300);
        $this->postJson($url, [...$body, 'items' => [['id' => $item->id, 'quantity' => 201]]])->assertUnprocessable();
        $second = $this->postJson($url, [...$body, 'items' => [['id' => $item->id, 'quantity' => 200]]])->assertOk()->json('id');
        app(NroSnapshotService::class)->ingest($a, $this->payload(500));
        $this->getJson('/admin/nro-shop/accounts/'.$a->id)->assertOk()->assertJsonPath('inventory.0.selectable', 0);
        $this->patchJson('/admin/nro-shop/listings/'.$first, ['status' => 'paused'])->assertOk();
        $this->getJson('/admin/nro-shop/accounts/'.$a->id)->assertOk()->assertJsonPath('inventory.0.selectable', 300);
        $third = $this->postJson($url, $body)->assertOk()->json('id');
        $this->patchJson('/admin/nro-shop/listings/'.$first, ['status' => 'active'])->assertUnprocessable();
        $buyer = User::factory()->create(['balance' => 1000]);
        app(NroShopService::class)->purchase($buyer, $second, 'khach', 10, (string) Str::uuid());
        $this->getJson('/admin/nro-shop/accounts/'.$a->id)->assertOk()->assertJsonPath('inventory.0.selectable', 0)->assertJsonPath('inventory.0.listed', 300)->assertJsonPath('inventory.0.reserved', 200);
        $this->assertEquals(1, app(NroShopService::class)->listing(DB::table('item_listings')->find($third))['available']);
        app(NroShopService::class)->purchase($buyer, $third, 'khach', 10, (string) Str::uuid());
        $this->assertEquals(500, DB::table('nro_inventory_items')->where('id', $item->id)->value('reserved'));
        $this->assertEquals(600, $buyer->fresh()->balance);
    }

    public function test_item_allowlist_is_enforced_on_create_republish_and_purchase_with_admin_only_settings(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $id = $this->listing($seller, $a);
        $seller->givePermissionTo('nro-settings.manage');
        $this->actingAs($seller, 'web')->patchJson('/admin/nro-shop/sale-policy', ['enabled' => true, 'ids' => [123]])->assertForbidden();
        $admin = User::factory()->create(); $admin->assignRole('admin'); $admin->givePermissionTo('nro-settings.manage');
        $this->actingAs($admin, 'web')->patchJson('/admin/nro-shop/sale-policy', ['enabled' => true, 'ids' => [-1]])->assertUnprocessable();
        $this->patchJson('/admin/nro-shop/sale-policy', ['enabled' => true, 'ids' => [123]])->assertOk();
        $item = DB::table('nro_inventory_items')->where('account_id', $a->id)->first();
        $body = ['title' => 'Blocked', 'price' => 100, 'items' => [['id' => $item->id, 'quantity' => 1]]];
        $this->actingAs($seller, 'web')->getJson('/admin/nro-shop/accounts/'.$a->id)->assertOk()->assertJsonPath('inventory.0.sellable', false);
        $this->postJson('/admin/nro-shop/accounts/'.$a->id.'/listings', $body)->assertUnprocessable();
        $this->patchJson('/admin/nro-shop/listings/'.$id, ['status' => 'paused'])->assertOk();
        $this->patchJson('/admin/nro-shop/listings/'.$id, ['status' => 'active'])->assertUnprocessable();
        DB::table('item_listings')->where('id', $id)->update(['status' => 'active']);
        $buyer = User::factory()->create(['balance' => 1000]); Passport::actingAs($buyer);
        $this->postJson('/api/nro-shop/orders', ['listingId' => $id, 'serverId' => 10, 'requestKey' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertEquals(1000, $buyer->fresh()->balance);
        $this->assertDatabaseCount('item_orders', 0);
        $this->actingAs($admin, 'web')->patchJson('/admin/nro-shop/sale-policy', ['enabled' => true, 'ids' => [0]])->assertOk();
        $this->actingAs($seller, 'web')->postJson('/admin/nro-shop/accounts/'.$a->id.'/listings', $body)->assertOk();
    }

    public function test_sale_policy_permission_can_be_delegated_without_viewing_other_sellers_data(): void
    {
        $seller = $this->seller(); $own = $this->warehouse($seller); $ownListing = $this->listing($seller, $own);
        $other = $this->seller(); $foreign = $this->warehouse($other); $foreignListing = $this->listing($other, $foreign);
        $buyer = User::factory()->create(['balance' => 1000]);
        $ownOrder = app(NroShopService::class)->purchase($buyer, $ownListing, 'khach', 10, (string) Str::uuid());
        app(NroShopService::class)->purchase($buyer, $foreignListing, 'khach', 10, (string) Str::uuid());
        $seller->givePermissionTo(['item-listings.view', 'item-orders.view', 'item-orders.reconcile', 'nro-settings.manage']);
        $this->actingAs($seller, 'web')->postJson('/admin/nro-shop/accounts/'.$own->id.'/scan')->assertOk();
        $this->actingAs($other, 'web')->postJson('/admin/nro-shop/accounts/'.$foreign->id.'/scan')->assertOk();
        $foreignJob = DB::table('nro_worker_jobs')->where('account_id', $foreign->id)->first();
        $this->actingAs($seller, 'web')->get('/admin/nro-shop')->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('capabilities.salePolicy', false)->has('accounts', 1)->where('accounts.0.id', $own->id));
        // Each tab endpoint scopes to the caller's own accounts, same as the props used to.
        $this->getJson('/admin/nro-shop/listings')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ownListing);
        $this->getJson('/admin/nro-shop/orders')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ownOrder);
        $this->getJson('/admin/nro-shop/jobs')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.account_id', $own->id);
        $this->patchJson('/admin/nro-shop/sale-policy', ['enabled' => false, 'ids' => []])->assertForbidden();
        $seller->givePermissionTo('nro-sale-policy.manage');
        $this->get('/admin/nro-shop')->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('capabilities.salePolicy', true)->has('accounts', 1));
        $this->patchJson('/admin/nro-shop/sale-policy', ['enabled' => true, 'ids' => [0]])->assertOk();
        $this->getJson('/admin/nro-shop/accounts/'.$foreign->id)->assertNotFound();
        $this->getJson('/admin/nro-shop/accounts/'.$foreign->id.'/listings')->assertNotFound();
        $this->postJson('/admin/nro-shop/accounts/'.$foreign->id.'/listings', [])->assertNotFound();
        $this->patchJson('/admin/nro-shop/listings/'.$foreignListing, ['status' => 'paused'])->assertNotFound();
        $this->postJson('/admin/nro-shop/jobs/'.$foreignJob->id.'/reconcile', ['resolution' => 'not_delivered', 'note' => 'Không được sửa kho người khác'])->assertNotFound();
        foreach (['admin', 'super-admin'] as $role) {
            Role::findOrCreate($role, 'web'); $manager = User::factory()->create(); $manager->assignRole($role);
            $manager->givePermissionTo(['nro-accounts.view', 'nro-accounts.manage', 'item-listings.view', 'item-orders.view']);
            $this->actingAs($manager, 'web')->get('/admin/nro-shop')->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->where('capabilities.salePolicy', true)->has('accounts', 2));
            $this->getJson('/admin/nro-shop/listings')->assertOk()->assertJsonPath('total', 2);
            $this->getJson('/admin/nro-shop/orders')->assertOk()->assertJsonPath('total', 2);
            $this->patchJson('/admin/nro-shop/sale-policy', ['enabled' => false, 'ids' => []])->assertOk();
            $this->getJson('/admin/nro-shop/accounts/'.$foreign->id)->assertOk();
        }
        $delegate = User::factory()->create(); $delegate->givePermissionTo('nro-sale-policy.manage');
        $this->actingAs($delegate, 'web')->get('/admin/nro-shop')->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->where('capabilities.salePolicy', true)->has('accounts', 0));
        // A sale-policy delegate has no listing/order permission, so the tabs stay closed to them.
        $this->getJson('/admin/nro-shop/listings')->assertForbidden();
        $this->getJson('/admin/nro-shop/orders')->assertForbidden();
        $this->patchJson('/admin/nro-shop/sale-policy', ['enabled' => false, 'ids' => []])->assertOk();
        $delegate->revokePermissionTo('nro-sale-policy.manage');
        $this->patchJson('/admin/nro-shop/sale-policy', ['enabled' => true, 'ids' => []])->assertForbidden();
        $this->assertDatabaseHas('item_orders', ['id' => $ownOrder, 'status' => 'awaiting_receipt']);
    }

    public function test_confirmed_missing_items_only_flags_refund_until_admin_acts(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $listing = $this->listing($seller, $a);
        $buyer = User::factory()->create(['balance' => 1000]);
        $order = app(NroShopService::class)->purchase($buyer, $listing, 'khach', 10, (string) Str::uuid());
        app(\App\Services\NroReceivingService::class)->start($buyer, $order, ['mode' => 'auto', 'username' => 'receiver', 'password' => 'private-receiver', 'requestKey' => (string) Str::uuid()]);
        $job = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk()->json('data');
        $payload = $this->payload(); $payload['completeness']['equipped'] ??= true;
        // Plenty of the same template with different options cannot replace the purchased item.
        $payload['snapshot']['bag'][0]['options'][0]['param'] = 99;
        $body = ['leaseToken' => $job['leaseToken'], 'outcome' => 'missing_items', 'payload' => $payload];
        $url = '/app/nro-worker/jobs/'.$job['id'].'/complete';
        $this->postJson($url, $body)->assertOk()->assertJsonPath('refunded', false)->assertJsonPath('refundRequested',true);
        $this->assertEquals(800, $buyer->fresh()->balance);
        $this->assertEquals(2, DB::table('nro_inventory_items')->where('account_id',$a->id)->sum('reserved'));
        $this->assertDatabaseHas('item_orders',['id'=>$order,'status'=>'awaiting_receipt','refund_requested'=>true]);
        $this->postJson($url,$body)->assertOk();
        $admin=User::factory()->create(); $admin->assignRole('admin');
        $refund=['amount'=>200,'note'=>'Đã kiểm tra kho thiếu đúng đồ.'];
        $this->actingAs($admin,'web')->postJson('/admin/nro-shop/orders/'.$order.'/refund',['amount'=>100,'note'=>$refund['note']])->assertUnprocessable();
        $this->postJson('/admin/nro-shop/orders/'.$order.'/refund',$refund)->assertOk();
        $this->postJson('/admin/nro-shop/orders/'.$order.'/refund',$refund)->assertOk();
        $this->postJson($url, $body)->assertOk();
        $this->assertEquals(1000, $buyer->fresh()->balance);
        $this->assertEquals(0, $seller->fresh()->balance);
        $this->assertEquals(0, DB::table('nro_inventory_items')->where('account_id', $a->id)->sum('reserved'));
        $this->assertDatabaseHas('item_orders', ['id' => $order, 'status' => 'refunded']);
        $this->assertDatabaseHas('nro_delivery_sessions', ['order_id' => $order, 'status' => 'failed', 'receiver_credentials' => null, 'receiver_lock' => null]);
        $this->assertDatabaseHas('item_listings', ['id' => $listing, 'status' => 'sold']);
        $this->assertSame('refunded', app(NroShopService::class)->listing(DB::table('item_listings')->find($listing))['lastOrderStatus']);
        $this->getJson('/api/nro-shop/listings/'.$listing)->assertNotFound();
        $this->actingAs($seller, 'web')->patchJson('/admin/nro-shop/listings/'.$listing, ['status' => 'active'])->assertUnprocessable();
    }

    public function test_login_failure_before_trade_is_reported_and_order_can_be_reopened_safely(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $listing = $this->listing($seller, $a);
        $buyer = User::factory()->create(['balance' => 1000]);
        $order = app(NroShopService::class)->purchase($buyer, $listing, '', 10, (string) Str::uuid());
        app(\App\Services\NroReceivingService::class)->start($buyer, $order, ['mode' => 'auto', 'username' => 'receiver', 'password' => 'private-receiver', 'requestKey' => (string) Str::uuid()]);
        $job = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk()->json('data');
        $message = 'Acc nhận: hành trang đã đầy. Hãy dọn túi rồi bấm Nhận đồ lại.';
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete', [
            'leaseToken' => $job['leaseToken'], 'outcome' => 'login_failed', 'message' => $message,
            'loginFailureKind' => 'inventory_full', 'retryable' => true, 'loginAccountRole' => 'receiver',
        ])->assertOk()->assertJsonPath('retrySafe', true);
        $this->assertDatabaseHas('item_orders', ['id' => $order, 'status' => 'awaiting_receipt', 'delivery_message' => $message]);
        $this->assertDatabaseHas('nro_delivery_sessions', ['order_id' => $order, 'status' => 'failed', 'receiver_credentials' => null, 'receiver_lock' => null]);
        $this->assertDatabaseHas('item_listings', ['id' => $listing, 'status' => 'sold']);
        $this->assertNotSame('login_blocked', $a->fresh()->publish_status);
        $this->assertGreaterThan(0, DB::table('nro_inventory_items')->where('account_id', $a->id)->sum('reserved'));
    }

    public function test_permanent_shop_login_failure_blocks_more_jobs_until_password_is_changed(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $listing = $this->listing($seller, $a);
        $buyer = User::factory()->create(['balance' => 1000]);
        $order = app(NroShopService::class)->purchase($buyer, $listing, '', 10, (string) Str::uuid());
        app(\App\Services\NroReceivingService::class)->start($buyer, $order, ['mode' => 'manual', 'recipientName' => 'khach', 'requestKey' => (string) Str::uuid()]);
        $job = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk()->json('data');
        $message = 'Acc kho: tài khoản đang bị khóa; đã dừng đăng nhập.';
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete', [
            'leaseToken' => $job['leaseToken'], 'outcome' => 'login_failed', 'message' => $message,
            'loginFailureKind' => 'AccountLocked', 'retryable' => false, 'loginAccountRole' => 'sender',
        ])->assertOk()->assertJsonPath('retrySafe', true);
        $this->assertDatabaseHas('nro_accounts', ['id' => $a->id, 'publish_status' => 'login_blocked', 'publish_error' => $message]);
        try {
            app(\App\Services\NroReceivingService::class)->start($buyer, $order, ['mode' => 'manual', 'recipientName' => 'khach', 'requestKey' => (string) Str::uuid()]);
            $this->fail('Acc kho bị khóa đăng nhập không được tạo thêm job.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('đang bị khóa', implode(' ', $e->errors()['nro'] ?? []));
        }
        $this->actingAs($seller, 'web')->patchJson('/admin/nro-shop/accounts/'.$a->id.'/password', ['password' => 'new-safe-password'])->assertOk();
        $this->assertDatabaseHas('nro_accounts', ['id' => $a->id, 'publish_status' => null, 'publish_error' => null]);
        app(\App\Services\NroReceivingService::class)->start($buyer, $order, ['mode' => 'manual', 'recipientName' => 'khach', 'requestKey' => (string) Str::uuid()]);
        $this->assertDatabaseHas('item_orders', ['id' => $order, 'status' => 'queued']);
    }

    public function test_shortage_refund_rejects_incomplete_stale_matching_and_unconfirmed_evidence(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $listing = $this->listing($seller, $a);
        $buyer = User::factory()->create(['balance' => 1000]);
        $order = app(NroShopService::class)->purchase($buyer, $listing, 'khach', 10, (string) Str::uuid());
        app(\App\Services\NroReceivingService::class)->start($buyer, $order, ['mode' => 'manual', 'recipientName' => 'khach', 'requestKey' => (string) Str::uuid()]);
        $job = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk()->json('data');
        $missing = $this->payload(0); $missing['completeness']['equipped'] = true;
        $url = '/app/nro-worker/jobs/'.$job['id'].'/complete';
        foreach (['bag', 'chest', 'equipped', 'stale', 'matching', 'trading', 'unknown', 'partial'] as $case) {
            $payload = $missing; $status = 422;
            if (in_array($case, ['bag', 'chest', 'equipped'])) $payload['completeness'][$case] = false;
            if ($case === 'stale') $payload['snapshot']['capturedAt'] = now()->subMinutes(6)->toIso8601String();
            if ($case === 'matching') {
                $payload = $this->payload(); $payload['completeness']['equipped'] = true;
                $payload['snapshot']['chest'] = [$payload['snapshot']['bag'][0]];
                $payload['snapshot']['equipped'] = [$payload['snapshot']['bag'][1]];
                $payload['snapshot']['bag'] = [];
            }
            if (in_array($case, ['trading', 'unknown'])) $status = 409;
            DB::table('nro_delivery_sessions')->where('order_id', $order)->update(['trade_in_flight' => $case === 'unknown' ? null : $case === 'trading']);
            DB::table('item_order_items')->where('order_id', $order)->update(['delivered' => $case === 'partial' ? 1 : 0]);
            $this->postJson($url, ['leaseToken' => $job['leaseToken'], 'outcome' => 'missing_items', 'payload' => $payload])->assertStatus($status);
            $this->assertEquals(800, $buyer->fresh()->balance, $case);
            $this->assertEquals(0, $seller->fresh()->balance, $case);
            $this->assertDatabaseCount('nro_account_snapshots', 1);
            $this->assertEquals(2, DB::table('nro_inventory_items')->where('account_id', $a->id)->sum('reserved'), $case);
            $this->assertDatabaseHas('nro_worker_jobs', ['id' => $job['id'], 'status' => 'processing']);
        }
        DB::table('item_order_items')->where('order_id', $order)->update(['delivered' => 0]);
        DB::table('nro_worker_jobs')->where('id', $job['id'])->update(['lease_until' => now()->subSecond()]);
        $this->postJson($url, ['leaseToken' => $job['leaseToken'], 'outcome' => 'missing_items', 'payload' => $missing])->assertOk()->assertJsonPath('retrySafe', true);
        $this->assertEquals(800, $buyer->fresh()->balance);
        $this->assertDatabaseHas('item_orders', ['id' => $order, 'status' => 'awaiting_receipt']);
        $this->assertEquals(2, DB::table('nro_inventory_items')->where('account_id', $a->id)->sum('reserved'));
        $this->assertDatabaseCount('nro_account_snapshots', 1);
    }

    public function test_worker_key_lease_and_completion_are_isolated_and_idempotent(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller);
        $this->actingAs($seller)->postJson('/admin/nro-shop/accounts/'.$a->id.'/scan')->assertOk();
        $this->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['snapshot']])->assertUnauthorized();
        $job = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['snapshot']])->assertOk()->json('data');
        $this->assertEquals('secret-pass', $job['account']['password']);
        $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['snapshot']])->assertOk()->assertJsonPath('data', null);
        $url = '/app/nro-worker/jobs/'.$job['id'].'/complete';
        $this->withToken($this->token)->postJson($url, ['leaseToken' => (string) Str::uuid(), 'outcome' => 'success', 'payload' => $this->payload()])->assertForbidden();
        $body = ['leaseToken' => $job['leaseToken'], 'outcome' => 'success', 'payload' => $this->payload()];
        $this->withToken($this->token)->postJson($url, $body)->assertOk();
        $this->withToken($this->token)->postJson($url, $body)->assertOk();
        $this->assertDatabaseCount('nro_account_snapshots', 2);
    }
    public function test_delivery_pays_seller_once_and_expiry_does_not_replay(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $listing = $this->listing($seller, $a);
        $buyer = User::factory()->create(['balance' => 1000]);
        $order = app(NroShopService::class)->purchase($buyer, $listing, 'khach', 10, (string) Str::uuid());
        $this->assertDatabaseCount('nro_worker_jobs', 0);
        app(\App\Services\NroReceivingService::class)->start($buyer, $order, ['mode' => 'manual', 'recipientName' => 'khach', 'requestKey' => (string) Str::uuid()]);
        $job = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk()->json('data');
        $this->assertEquals($order, $job['order']['id']);
        $lines = DB::table('item_order_items')->where('order_id', $order)->get()->map(fn ($i) => ['id' => $i->id, 'delivered' => $i->quantity])->all();
        $this->withToken($this->token)->postJson('/app/nro-worker/jobs/'.$job['id'].'/progress', ['leaseToken' => $job['leaseToken'], 'items' => $lines])->assertOk();
        $this->travel(4)->minutes();
        $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseHas('item_orders', ['id' => $order, 'status' => 'review']);
        $body = ['leaseToken' => $job['leaseToken'], 'outcome' => 'success', 'payload' => $this->payload(1)];
        $url = '/app/nro-worker/jobs/'.$job['id'].'/complete';
        $this->withToken($this->token)->postJson($url, $body)->assertOk(); $this->withToken($this->token)->postJson($url, $body)->assertOk();
        $this->assertEquals(200, $seller->fresh()->balance);
        $this->assertEquals(0, DB::table('nro_inventory_items')->where('account_id', $a->id)->sum('reserved'));
        $this->assertDatabaseHas('item_orders', ['id' => $order, 'status' => 'completed']);
    }
    public function test_insufficient_bundle_and_balance_leave_no_partial_order(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $listing = $this->listing($seller, $a);
        $buyer = User::factory()->create(['balance' => 100]); Passport::actingAs($buyer);
        $body = ['listingId' => $listing, 'recipientName' => 'khach', 'serverId' => 10, 'requestKey' => (string) Str::uuid()];
        $this->postJson('/api/nro-shop/orders', $body)->assertUnprocessable();
        $buyer->update(['balance' => 1000]);
        DB::table('nro_inventory_items')->where('id', DB::table('nro_inventory_items')->min('id'))->update(['quantity' => 0]);
        $this->postJson('/api/nro-shop/orders', $body)->assertUnprocessable();
        $this->assertDatabaseCount('item_orders', 0); $this->assertDatabaseCount('item_inventory_reservations', 0);
        $this->assertEquals(1000, $buyer->fresh()->balance);
    }

    public function test_stopping_before_trade_allows_receiving_again_without_another_payment(): void
    {
        foreach (['preparing', 'ready'] as $phase) {
            $seller = $this->seller(); $a = $this->warehouse($seller); $listing = $this->listing($seller, $a);
            $buyer = User::factory()->create(['balance' => 1000]);
            $order = app(NroShopService::class)->purchase($buyer, $listing, 'khach', 10, (string) Str::uuid());
            $receiving = app(\App\Services\NroReceivingService::class);
            $receiving->start($buyer, $order, ['mode' => 'manual', 'recipientName' => 'khach', 'requestKey' => (string) Str::uuid()]);
            $job = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk()->json('data');
            $url = '/app/nro-worker/jobs/'.$job['id']; $lease = ['leaseToken' => $job['leaseToken']];
            if ($phase === 'ready') $this->postJson($url.'/ready', [...$lease, 'characterId' => 10, 'name' => 'bot', 'mapId' => 5, 'zone' => 7, 'recipientName' => 'khach'])->assertOk();
            $this->postJson($url.'/complete', [...$lease, 'outcome' => 'review', 'message' => 'TaskCanceledException'])->assertOk()->assertJsonPath('retrySafe', true);
            $this->postJson($url.'/complete', [...$lease, 'outcome' => 'review'])->assertOk();
            $this->assertDatabaseHas('item_orders', ['id' => $order, 'status' => 'awaiting_receipt']);
            $this->assertEquals(2, DB::table('nro_inventory_items')->where('account_id', $a->id)->sum('reserved'));
            $this->assertEquals(800, $buyer->fresh()->balance);
            $this->assertEquals(0, $seller->fresh()->balance);
            $receiving->start($buyer, $order, ['mode' => 'manual', 'recipientName' => 'khach', 'requestKey' => (string) Str::uuid()]);
            $this->assertEquals(800, $buyer->fresh()->balance);
            $this->postJson($url.'/begin-round', $lease)->assertConflict();
            // Finish queued retry fixture so the next iteration claims its own job.
            DB::table('nro_worker_jobs')->where('order_id', $order)->where('status', 'queued')->update(['status' => 'failed']);
        }
    }

    public function test_lost_worker_retries_only_sessions_with_no_unconfirmed_trade(): void
    {
        foreach ([false, true, null] as $inFlight) {
            $seller = $this->seller(); $a = $this->warehouse($seller); $listing = $this->listing($seller, $a);
            $buyer = User::factory()->create(['balance' => 1000]);
            $order = app(NroShopService::class)->purchase($buyer, $listing, 'khach', 10, (string) Str::uuid());
            $sessionId = app(\App\Services\NroReceivingService::class)->start($buyer, $order, ['mode' => 'manual', 'recipientName' => 'khach', 'requestKey' => (string) Str::uuid()]);
            $job = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk()->json('data');
            DB::table('nro_delivery_sessions')->where('id', $sessionId)->update(['trade_in_flight' => $inFlight]);
            $this->travel(4)->minutes();
            $this->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk();
            $this->assertDatabaseHas('item_orders', ['id' => $order, 'status' => $inFlight === false ? 'awaiting_receipt' : 'review']);
            $this->assertEquals(2, DB::table('nro_inventory_items')->where('account_id', $a->id)->sum('reserved'));
            $this->travelBack();
        }
    }

    public function test_interrupted_snapshot_jobs_never_become_orphaned_in_review(): void
    {
        $a = $this->warehouse($this->seller());
        $a->update(['auto_publish' => true, 'publish_status' => 'waiting_snapshot']);
        $firstId = DB::table('nro_worker_jobs')->insertGetId(['account_id' => $a->id, 'type' => 'snapshot', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        $first = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['snapshot']])->assertOk()->json('data');
        $this->assertSame($firstId, $first['id']);
        $this->postJson('/app/nro-worker/jobs/'.$firstId.'/complete', [
            'leaseToken' => $first['leaseToken'], 'outcome' => 'review', 'message' => 'Journal cũ của snapshot',
        ])->assertOk();
        $this->assertDatabaseHas('nro_worker_jobs', ['id' => $firstId, 'status' => 'failed']);

        $secondId = DB::table('nro_worker_jobs')->insertGetId(['account_id' => $a->id, 'type' => 'snapshot', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        $second = $this->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['snapshot']])->assertOk()->json('data');
        $this->assertSame($secondId, $second['id']);
        $this->travel(4)->minutes();
        $this->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['snapshot']])->assertOk();
        $this->assertDatabaseHas('nro_worker_jobs', ['id' => $secondId, 'status' => 'failed']);
        $this->assertDatabaseMissing('nro_worker_jobs', ['account_id' => $a->id, 'status' => 'review']);
        $this->assertDatabaseHas('nro_accounts', ['id' => $a->id, 'publish_status' => 'scan_failed']);
        $this->travelBack();
    }

    public function test_unclaimable_jobs_are_resolved_and_rotated_key_can_flush_journal(): void
    {
        $seller = $this->seller(); $blocked = $this->warehouse($seller);
        $blocked->update(['last_synced_at' => null, 'publish_status' => 'login_blocked', 'publish_error' => 'Acc bị khóa đăng nhập.']);
        $blockedJob = DB::table('nro_worker_jobs')->insertGetId(['account_id' => $blocked->id, 'type' => 'snapshot', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['snapshot']])->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseHas('nro_worker_jobs', ['id' => $blockedJob, 'status' => 'failed']);
        $this->assertDatabaseMissing('nro_worker_jobs', ['account_id' => $blocked->id, 'status' => 'queued']);

        $seller2 = $this->seller(); $a = $this->warehouse($seller2);
        $jobId = DB::table('nro_worker_jobs')->insertGetId(['account_id' => $a->id, 'type' => 'snapshot', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        $job = $this->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['snapshot']])->assertOk()->json('data');
        $newToken = 'nrow_'.Str::random(64);
        DB::table('nro_worker_keys')->insert(['name' => 'replacement', 'token_hash' => hash('sha256', $newToken), 'accepts_delivery' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken($newToken)->postJson('/app/nro-worker/jobs/'.$jobId.'/complete', [
            'leaseToken' => $job['leaseToken'], 'outcome' => 'failed', 'message' => 'Gửi lại journal bằng key mới.',
        ])->assertOk();
        $this->assertDatabaseHas('nro_worker_jobs', ['id' => $jobId, 'status' => 'failed']);
        DB::table('nro_worker_jobs')->where('id', $jobId)->update(['lease_token' => null]);
        $this->withToken($newToken)->postJson('/app/nro-worker/jobs/'.$jobId.'/complete', [
            'leaseToken' => (string) Str::uuid(), 'outcome' => 'failed', 'message' => 'Journal cũ sau khi backend đã chốt.',
        ])->assertOk()->assertJsonPath('alreadyFinalized', true);

        $listing = $this->listing($seller2, $a); $buyer = User::factory()->create(['balance' => 1000]);
        $order = app(NroShopService::class)->purchase($buyer, $listing, 'khach', 10, (string) Str::uuid());
        $legacy = DB::table('nro_worker_jobs')->insertGetId(['account_id' => $a->id, 'order_id' => $order, 'type' => 'delivery', 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken($newToken)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseHas('nro_worker_jobs', ['id' => $legacy, 'status' => 'review']);
        $this->assertDatabaseHas('item_orders', ['id' => $order, 'status' => 'review']);
    }

    public function test_stopping_after_confirmed_round_keeps_only_remaining_items_reserved(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $listing = $this->listing($seller, $a);
        $buyer = User::factory()->create(['balance' => 1000]);
        $order = app(NroShopService::class)->purchase($buyer, $listing, 'khach', 10, (string) Str::uuid());
        app(\App\Services\NroReceivingService::class)->start($buyer, $order, ['mode' => 'manual', 'recipientName' => 'khach', 'requestKey' => (string) Str::uuid()]);
        $job = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk()->json('data');
        $url = '/app/nro-worker/jobs/'.$job['id']; $lease = ['leaseToken' => $job['leaseToken']];
        $this->postJson($url.'/ready', [...$lease, 'characterId' => 10, 'name' => 'bot', 'mapId' => 5, 'zone' => 7, 'recipientName' => 'khach'])->assertOk();
        $this->postJson($url.'/begin-round', $lease)->assertOk();
        $lines = DB::table('item_order_items')->where('order_id', $order)->orderBy('id')->get()->values()->map(fn ($i, $index) => ['id' => $i->id, 'delivered' => $index === 0 ? 1 : 0])->all();
        $this->postJson($url.'/progress', [...$lease, 'items' => $lines])->assertOk();
        $this->postJson($url.'/complete', [...$lease, 'outcome' => 'review'])->assertOk()->assertJsonPath('retrySafe', true);
        $this->assertEquals(1, DB::table('nro_inventory_items')->where('account_id', $a->id)->sum('reserved'));
        $this->assertDatabaseHas('item_orders', ['id' => $order, 'status' => 'awaiting_receipt']);
    }

    public function test_listing_explains_stock_separately_from_selling_availability(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $id = $this->listing($seller, $a);
        $listing = DB::table('item_listings')->find($id);
        $a->update(['last_synced_at' => null]);
        $data = app(NroShopService::class)->listing($listing);
        $this->assertEquals(2, $data['stockAvailable']);
        $this->assertEquals(0, $data['available']);
        $this->assertContains('Đang chờ tool cập nhật tồn kho sau thay đổi', $data['unavailableReasons']);
        DB::table('nro_inventory_items')->where('account_id', $a->id)->update(['reserved' => 2]);
        $data = app(NroShopService::class)->listing($listing);
        $this->assertEquals(0, $data['stockAvailable']);
        $this->assertContains('Đồ đã được giữ cho đơn chưa nhận', $data['unavailableReasons']);
    }

    public function test_old_progress_retry_cannot_clear_a_new_unconfirmed_trade(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $listing = $this->listing($seller, $a);
        $buyer = User::factory()->create(['balance' => 1000]);
        $order = app(NroShopService::class)->purchase($buyer, $listing, 'khach', 10, (string) Str::uuid());
        app(\App\Services\NroReceivingService::class)->start($buyer, $order, ['mode' => 'manual', 'recipientName' => 'khach', 'requestKey' => (string) Str::uuid()]);
        $job = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk()->json('data');
        $url = '/app/nro-worker/jobs/'.$job['id']; $lease = ['leaseToken' => $job['leaseToken']];
        $this->postJson($url.'/ready', [...$lease, 'characterId' => 10, 'name' => 'bot', 'mapId' => 5, 'zone' => 7, 'recipientName' => 'khach'])->assertOk();
        $this->postJson($url.'/begin-round', $lease)->assertOk();
        $lines = DB::table('item_order_items')->where('order_id', $order)->orderBy('id')->get()->values()->map(fn ($i, $index) => ['id' => $i->id, 'delivered' => $index === 0 ? 1 : 0])->all();
        $this->postJson($url.'/progress', [...$lease, 'items' => $lines])->assertOk();
        $this->postJson($url.'/begin-round', $lease)->assertOk();
        $this->postJson($url.'/progress', [...$lease, 'items' => $lines])->assertOk();
        $this->postJson($url.'/complete', [...$lease, 'outcome' => 'review'])->assertOk();
        $this->assertDatabaseHas('item_orders', ['id' => $order, 'status' => 'review']);
        $this->assertDatabaseHas('nro_delivery_sessions', ['id' => $job['receiving']['id'], 'trade_in_flight' => true]);
        $this->assertEquals(1, DB::table('nro_inventory_items')->where('account_id', $a->id)->sum('reserved'));
    }
    public function test_nick_snapshot_can_be_published_without_images_and_preserves_old_nicks(): void
    {
        $seller = $this->seller(); $seller->assignRole('admin'); $a = $this->warehouse($seller); $a->update(['usage_type' => 'nick']);
        $game = GameType::create(['name' => 'NRO']);
        $category = Category::create(['name' => 'Nick NRO', 'slug' => 'nick-nro', 'game_type_id' => $game->id, 'template' => 'default', 'status' => 'active']);
        $id = $this->actingAs($seller)->postJson('/admin/nro-shop/accounts/'.$a->id.'/nick', ['categoryId' => $category->id, 'price' => 200])->assertOk()->json('id');
        $this->getJson('/api/nick/'.$id)->assertOk()->assertJsonPath('data.nro_snapshot.data.character.name', 'botgame')->assertDontSee('secret-pass')->assertDontSee('game_password');
        $this->getJson('/api/categories/nick-nro/nicks')->assertOk()->assertJsonPath('data.0.nro_summary.serverIndex', 0)->assertJsonMissingPath('data.0.nro_snapshot');
        $this->actingAs($seller)->postJson('/admin/nro-shop/accounts/'.$a->id.'/nick', ['categoryId' => $category->id, 'price' => 200])->assertUnprocessable();
    }
    private function nickAttributesFixture(): array
    {
        $seller = $this->seller();
        $a = $this->warehouse($seller); $a->update(['usage_type' => 'nick']);
        DB::table('servers')->where('id', 10)->update(['name' => '12 sao', 'name_view' => 'Vũ Trụ 12']);
        $category = Category::create(['name' => 'Nick NRO', 'slug' => 'nick-nro', 'game_type_id' => GameType::create(['name' => 'NRO'])->id, 'template' => 'default', 'status' => 'active']);
        $seller->categories()->attach($category->id, ['can_post' => true]);
        $fields = [];
        foreach (['Hành Tinh' => ['Trái Đất', 'Namec'], 'Sever' => ['1sao', '12sao', '8910sao'], 'Cải Trang' => ['CT Goku', 'CT Goku SSJ'], 'Đăng kí' => ['Ảo', 'Gmail']] as $name => $options) {
            $attribute = \App\Models\Attribute::create(['name' => $name, 'status' => 1]);
            $category->attributes()->attach($attribute->id);
            $fields[$name] = ['id' => $attribute->id, 'options' => []];
            foreach ($options as $option) $fields[$name]['options'][] = $attribute->options()->create(['option_value' => $option, 'status' => 1])->id;
        }
        $snapshot = \App\Models\NroAccountSnapshot::findOrFail($a->latest_snapshot_id);
        $data = $snapshot->data_json;
        $data['equipped'] = [['type' => 5, 'name' => 'Cải trang Goku SSJ', 'quantity' => 1, 'slot' => 0, 'templateId' => 999, 'options' => []]];
        $snapshot->update(['data_json' => $data]);
        return [$seller, $a, $category, $fields, $snapshot];
    }

    public function test_snapshot_attributes_are_reviewable_and_use_existing_public_filters(): void
    {
        [$seller, $a, $category, $fields, $snapshot] = $this->nickAttributesFixture();
        $base = '/admin/nro-shop/accounts/'.$a->id;
        $preview = $this->actingAs($seller)->getJson($base.'/nick-attributes?categoryId='.$category->id)->assertOk()->json();
        $this->assertEquals($snapshot->id, $preview['snapshotId']);
        $byName = collect($preview['fields'])->keyBy('name');
        $this->assertEquals($fields['Hành Tinh']['options'][0], $byName['Hành Tinh']['selectedId']);
        $this->assertEquals($fields['Sever']['options'][1], $byName['Sever']['selectedId']);
        $this->assertEquals([$fields['Cải Trang']['options'][1]], $byName['Cải Trang']['suggestedIds']);
        $this->assertEquals($fields['Đăng kí']['options'][0], $byName['Đăng kí']['selectedId']);
        $selections = collect($preview['fields'])->mapWithKeys(fn ($f) => [$f['id'] => $f['selectedId']])->all();
        $selections[$fields['Đăng kí']['id']] = $fields['Đăng kí']['options'][1];
        $payload = ['categoryId' => $category->id, 'snapshotId' => $snapshot->id, 'price' => 200, 'attributeSelections' => $selections];
        $id = $this->postJson($base.'/nick', $payload)->assertOk()->json('id');
        $this->assertDatabaseHas('nick_attributes', ['nick_id' => $id, 'attribute_id' => $fields['Sever']['id'], 'attribute_option_id' => $fields['Sever']['options'][1]]);
        $cache = json_decode($this->getJson('/api/nick/'.$id)->assertOk()->json('data.attribute_cache_json'), true);
        $this->assertEquals(['Hành Tinh' => 'Trái Đất', 'Sever' => '12sao', 'Cải Trang' => 'CT Goku SSJ', 'Đăng kí' => 'Gmail'], $cache);
        $filter = '/api/categories/nick-nro/nicks?attr_'.$fields['Cải Trang']['id'].'=';
        $this->getJson($filter.$fields['Cải Trang']['options'][1])->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson($filter.$fields['Cải Trang']['options'][0])->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($base.'/nick', ['categoryId' => $category->id, 'nickId' => $id, 'price' => 250])->assertOk();
        $this->assertDatabaseHas('nick_attributes', ['nick_id' => $id, 'attribute_id' => $fields['Đăng kí']['id'], 'attribute_option_id' => $fields['Đăng kí']['options'][1]]);
        $this->postJson($base.'/nick', [...$payload, 'nickId' => $id, 'attributeSelections' => []])->assertOk();
        $this->assertDatabaseCount('nick_attributes', 0);
        $this->getJson('/api/nick/'.$id)->assertOk()->assertJsonPath('data.attribute_cache_json', '{}');
    }

    public function test_snapshot_attributes_reject_foreign_options_stale_previews_and_other_ctvs(): void
    {
        [$seller, $a, $category, $fields, $snapshot] = $this->nickAttributesFixture();
        $base = '/admin/nro-shop/accounts/'.$a->id;
        $body = ['categoryId' => $category->id, 'price' => 200];
        $this->actingAs($this->seller())->getJson($base.'/nick-attributes?categoryId='.$category->id)->assertNotFound();
        $this->actingAs($seller)->postJson($base.'/nick', [...$body, 'snapshotId' => $snapshot->id + 100])->assertUnprocessable();
        $this->postJson($base.'/nick', [...$body, 'attributeSelections' => [$fields['Hành Tinh']['id'] => $fields['Đăng kí']['options'][0]]])->assertUnprocessable();
        $this->postJson($base.'/nick', [...$body, 'attributeSelections' => [99999 => $fields['Hành Tinh']['options'][0]]])->assertUnprocessable();
        \App\Models\AttributeOption::find($fields['Hành Tinh']['options'][0])->update(['status' => 0]);
        $this->postJson($base.'/nick', [...$body, 'attributeSelections' => [$fields['Hành Tinh']['id'] => $fields['Hành Tinh']['options'][0]]])->assertUnprocessable();
        $this->assertDatabaseCount('nicks', 0); $this->assertDatabaseCount('nick_attributes', 0);
        $seller->categories()->detach($category->id);
        $this->getJson($base.'/nick-attributes?categoryId='.$category->id)->assertForbidden();
    }

    public function test_multiple_costumes_need_selection_and_merged_servers_use_display_mapping(): void
    {
        [$seller, $a, $category, $fields, $snapshot] = $this->nickAttributesFixture();
        $data = $snapshot->data_json;
        $data['chest'][] = ['type' => 5, 'name' => 'Goku', 'quantity' => 1];
        $snapshot->update(['data_json' => $data]);
        DB::table('servers')->where('id', 10)->update(['name' => '10 sao', 'name_view' => 'Vũ Trụ Gộp (8910)']);
        $preview = $this->actingAs($seller)->getJson('/admin/nro-shop/accounts/'.$a->id.'/nick-attributes?categoryId='.$category->id)->assertOk()->json('fields');
        $byName = collect($preview)->keyBy('name');
        $this->assertNull($byName['Cải Trang']['selectedId']);
        $this->assertEquals($fields['Cải Trang']['options'], $byName['Cải Trang']['suggestedIds']);
        $this->assertEquals($fields['Sever']['options'][2], $byName['Sever']['selectedId']);
    }

    public function test_admin_account_rows_show_linked_nick_and_edit_data_without_credentials(): void
    {
        [$seller, $a, $category] = $this->nickAttributesFixture();
        config(['nro-shop.frontend_url' => 'https://shop.example.test/']);
        $base = '/admin/nro-shop/accounts/'.$a->id;
        $id = $this->actingAs($seller)->postJson($base.'/nick', ['categoryId' => $category->id, 'price' => 123456, 'description' => 'Mô tả đang đăng'])->assertOk()->json('id');
        $other = $this->warehouse($this->seller());
        $this->actingAs($seller)->get('/admin/nro-shop')->assertOk()->assertInertia(fn ($page) => $page
            ->has('accounts', 1)->where('accounts.0.id', $a->id)
            ->where('accounts.0.nick.id', $id)->where('accounts.0.nick.status', 'not_sold')
            ->where('accounts.0.nick.categoryName', $category->name)->where('accounts.0.nick.price', '123456')
            ->where('shopUrl', 'https://shop.example.test')->where('capabilities.editNick', true)
            ->missing('accounts.0.game_password')->missing('accounts.0.nick.account_password'));
        $this->getJson($base)->assertOk()->assertJsonPath('nick.id', $id)->assertJsonPath('nick.categoryId', $category->id)
            ->assertJsonPath('nick.price', '123456')->assertJsonPath('nick.description', 'Mô tả đang đăng')
            ->assertDontSee('secret-pass')->assertJsonMissingPath('nick.account_password');
        $seller->givePermissionTo('nicks.create'); $seller->revokePermissionTo('nicks.manage');
        $this->get('/admin/nro-shop')->assertOk()->assertInertia(fn ($page) => $page->where('capabilities.publishNick', true)->where('capabilities.editNick', false));
        $this->postJson($base.'/nick', ['categoryId' => $category->id, 'nickId' => $id, 'price' => 1])->assertForbidden();
        $seller->givePermissionTo(['nicks.manage', 'nro-settings.manage']);
        Nick::withoutUserOwnedScope()->findOrFail($id)->update(['status' => 'sold']); $a->update(['status' => 'sold']);
        $this->get('/admin/nro-shop')->assertOk()->assertInertia(fn ($page) => $page->where('accounts.0.nick.status', 'sold')->where('accounts.0.status', 'sold'));
        $this->postJson($base.'/scan')->assertUnprocessable();
        $this->postJson($base.'/nick', ['categoryId' => $category->id, 'nickId' => $id, 'price' => 1])->assertUnprocessable();
        $this->patchJson($base.'/password', ['password' => 'new-pass'])->assertUnprocessable();
        $this->patchJson($base.'/settings', ['server_id' => 10, 'server_game_id' => 37, 'delivery_map' => 24, 'delivery_zone' => 0, 'wait_minutes' => 10])->assertUnprocessable();
        $this->assertDatabaseCount('nro_worker_jobs', 0);
    }

    public function test_warehouse_listing_counts_and_paginated_details_are_owner_scoped(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller);
        $other = $this->warehouse($this->seller());
        for ($i = 0; $i < 23; $i++) DB::table('item_listings')->insert(['account_id' => $a->id, 'user_id' => $seller->id, 'title' => 'Gói '.$i, 'price' => 200, 'description' => '', 'status' => $i < 21 ? 'active' : 'paused', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('item_listings')->insert(['account_id' => $other->id, 'user_id' => $other->user_id, 'title' => 'PRIVATE_OTHER_SELLER', 'price' => 200, 'description' => '', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($seller)->get('/admin/nro-shop')->assertOk()->assertInertia(fn ($page) => $page
            ->has('accounts', 1)->where('accounts.0.listingCounts.total', 23)->where('accounts.0.listingCounts.active', 21));
        $url = '/admin/nro-shop/accounts/'.$a->id.'/listings';
        $this->getJson($url)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('total', 23)->assertJsonPath('data.0.accountId', $a->id)->assertDontSee('PRIVATE_OTHER_SELLER');
        $this->getJson($url.'?page=2')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('page', 2);
        $this->getJson('/admin/nro-shop/accounts/'.$other->id.'/listings')->assertNotFound();
        $seller->syncPermissions(['nro-accounts.view']);
        $this->getJson($url)->assertForbidden();
        $this->get('/admin/nro-shop')->assertOk()->assertInertia(fn ($page) => $page->where('accounts.0.listingCounts', null));
        $this->getJson('/admin/nro-shop/listings')->assertForbidden();
    }

    public function test_only_admin_can_create_and_revoke_worker_keys(): void
    {
        $seller = $this->seller();
        $this->actingAs($seller)->postJson('/admin/nro-shop/worker-keys', ['name' => 'CTV'])->assertForbidden();
        $seller->assignRole('admin'); $seller->givePermissionTo('nro-workers.manage');
        $key = $this->actingAs($seller)->postJson('/admin/nro-shop/worker-keys', ['name' => 'Máy test'])->assertOk()->json();
        $this->assertEquals(hash('sha256', $key['token']), DB::table('nro_worker_keys')->where('id', $key['id'])->value('token_hash'));
        $this->actingAs($seller)->deleteJson('/admin/nro-shop/worker-keys/'.$key['id'])->assertOk();
        $this->withToken($key['token'])->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['snapshot']])->assertUnauthorized();
    }
    public function test_shop_requires_delivery_worker_but_accepts_complete_old_snapshot(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $id = $this->listing($seller, $a);
        $buyer = User::factory()->create(['balance' => 1000]); Passport::actingAs($buyer);
        DB::table('nro_worker_keys')->update(['accepts_delivery' => false]);
        $body = ['listingId' => $id, 'recipientName' => 'khach', 'serverId' => 10, 'requestKey' => (string) Str::uuid()];
        $this->postJson('/api/nro-shop/orders', $body)->assertUnprocessable();
        DB::table('nro_worker_keys')->update(['accepts_delivery' => true]);
        $payload = $this->payload(); $payload['snapshot']['capturedAt'] = now()->subHours(2)->toIso8601String();
        app(NroSnapshotService::class)->ingest($a, $payload);
        $this->postJson('/api/nro-shop/orders', $body)->assertOk();
        $this->assertEquals(800, $buyer->fresh()->balance); $this->assertDatabaseCount('item_orders', 1);
    }

    public function test_receiving_timeout_keeps_remaining_stock_and_resumes_without_another_payment(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $listing = $this->listing($seller, $a);
        $buyer = User::factory()->create(['balance' => 1000]);
        $order = app(NroShopService::class)->purchase($buyer, $listing, '', 10, (string) Str::uuid());
        $this->assertDatabaseCount('nro_worker_jobs', 0);
        $service = app(\App\Services\NroReceivingService::class);
        $request = ['mode' => 'manual', 'recipientName' => 'khach', 'requestKey' => (string) Str::uuid()];
        $id = $service->start($buyer, $order, $request);
        $this->assertEquals($id, $service->start($buyer, $order, $request));
        $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 2, 'types' => ['delivery']])->assertOk()->assertJsonPath('data', null);
        $job = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk()->json('data');
        $this->assertEquals(5, $job['account']['deliveryMap']);
        $this->assertEquals('auto', $job['account']['deliveryZoneMode']);
        $this->assertEquals('Đảo Kame', app(NroShopService::class)->order($order)['deliveryLocation']['mapName']);
        $this->assertEquals('127.0.0.1', $job['account']['host']); $this->assertEquals(14445, $job['account']['port']);
        $url = '/app/nro-worker/jobs/'.$job['id']; $lease = ['leaseToken' => $job['leaseToken']];
        $ready = [...$lease, 'characterId' => 10, 'name' => 'bot', 'mapId' => 5, 'mapName' => 'Đảo Kame', 'zone' => 7, 'recipientName' => 'khach'];
        $first = $this->postJson($url.'/ready', $ready)->assertOk()->json('expiresAt');
        $this->assertEquals(7, app(NroShopService::class)->order($order)['session']['position']['zone']);
        $this->assertEqualsWithDelta(1800, now()->diffInSeconds(\Carbon\Carbon::parse($first), false), 2);
        $this->postJson($url.'/begin-round', $lease)->assertOk();
        $this->postJson($url.'/complete', [...$lease, 'outcome' => 'expired'])->assertConflict();
        $line = DB::table('item_order_items')->where('order_id', $order)->first();
        $progress = [...$lease, 'items' => DB::table('item_order_items')->where('order_id', $order)->get()->map(fn ($i) => ['id' => $i->id, 'delivered' => $i->id === $line->id ? 1 : 0])->all()];
        $this->postJson($url.'/progress', $progress)->assertOk(); $this->postJson($url.'/progress', $progress)->assertOk();
        $this->assertEquals(1, DB::table('nro_inventory_items')->where('account_id', $a->id)->sum('reserved'));
        $this->travel(1)->minutes();
        $this->postJson($url.'/ready', $ready)->assertOk()->assertJsonPath('expiresAt', $first);
        $this->postJson($url.'/complete', [...$lease, 'outcome' => 'success', 'payload' => $this->payload()])->assertConflict();
        $this->travel(30)->minutes();
        $this->postJson($url.'/complete', [...$lease, 'outcome' => 'expired'])->assertOk();
        $this->postJson($url.'/complete', [...$lease, 'outcome' => 'expired'])->assertOk();
        $this->assertDatabaseHas('item_orders', ['id' => $order, 'status' => 'awaiting_receipt']);
        $this->assertEquals(1, DB::table('nro_inventory_items')->where('account_id', $a->id)->sum('reserved'));
        $service->start($buyer, $order, [...$request, 'requestKey' => (string) Str::uuid()]);
        $next = $this->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk()->json('data');
        $this->assertEquals(1, collect($next['order']['items'])->sum('delivered'));
        $this->assertEquals(800, $buyer->fresh()->balance); $this->assertEquals(0, $seller->fresh()->balance);
    }

    public function test_auto_receiving_credentials_are_private_and_deleted_after_session(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $listing = $this->listing($seller, $a);
        $buyer = User::factory()->create(['balance' => 1000]);
        $order = app(NroShopService::class)->purchase($buyer, $listing, '', 10, (string) Str::uuid());
        $session = app(\App\Services\NroReceivingService::class)->start($buyer, $order, ['mode' => 'auto', 'username' => 'customer', 'password' => 'PRIVATE_RECEIVER', 'requestKey' => (string) Str::uuid()]);
        $this->assertStringNotContainsString('PRIVATE_RECEIVER', DB::table('nro_delivery_sessions')->where('id', $session)->value('receiver_credentials'));
        $this->assertStringNotContainsString('PRIVATE_RECEIVER', json_encode(app(NroShopService::class)->order($order)));
        $job = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertEquals('PRIVATE_RECEIVER', $job['receiving']['receiver']['password']);
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/ready', ['leaseToken' => $job['leaseToken'], 'characterId' => 10, 'name' => 'bot', 'mapId' => 5, 'zone' => 7, 'recipientName' => 'receiver'])->assertOk();
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/begin-round', ['leaseToken' => $job['leaseToken']])->assertOk();
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete', ['leaseToken' => $job['leaseToken'], 'outcome' => 'review'])->assertOk();
        $this->assertNull(DB::table('nro_delivery_sessions')->where('id', $session)->value('receiver_credentials'));
        $this->assertNotNull(DB::table('nro_delivery_sessions')->where('id', $session)->value('receiver_lock'));
    }

    public function test_order_only_ctv_cannot_manage_accounts_packages_or_keys(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller);
        $seller->syncPermissions(['item-orders.view']);
        $this->actingAs($seller)->getJson('/admin/nro-shop/accounts/'.$a->id)->assertForbidden();
        $this->postJson('/admin/nro-shop/accounts/'.$a->id.'/scan')->assertForbidden();
        $this->postJson('/admin/nro-shop/accounts/'.$a->id.'/listings')->assertForbidden();
        $this->postJson('/admin/nro-shop/worker-keys', ['name' => 'x'])->assertForbidden();
        $this->get('/admin/nro-shop')->assertOk()->assertInertia(fn ($page) => $page->has('accounts', 0)->where('capabilities.orders', true));
        $this->getJson('/admin/nro-shop/listings')->assertForbidden();
        $this->getJson('/admin/nro-shop/worker-keys')->assertForbidden();
        $this->getJson('/admin/nro-shop/orders')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_other_buyer_cannot_start_receiving_and_old_worker_is_rejected(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $listing = $this->listing($seller, $a);
        $buyer = User::factory()->create(['balance' => 1000]);
        $order = app(NroShopService::class)->purchase($buyer, $listing, '', 10, (string) Str::uuid());
        Passport::actingAs(User::factory()->create());
        $this->postJson('/api/nro-shop/orders/'.$order.'/receive', ['mode' => 'manual', 'recipientName' => 'khach', 'requestKey' => (string) Str::uuid()])->assertNotFound();
        $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['types' => ['delivery']])->assertUnprocessable();
        $this->assertDatabaseCount('nro_delivery_sessions', 0);
    }

    public function test_worker_routes_only_live_under_app_and_keep_separate_authentication(): void
    {
        $this->withToken($this->token)->postJson('/api/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['snapshot']])->assertNotFound();
        $this->get('/app/nro-worker/accounts', ['Authorization' => ''])->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $this->withToken($this->token)->getJson('/app/nro-worker/accounts')->assertOk();
        $this->withToken($this->token)->getJson('/app/v2/bots')->assertUnauthorized();
    }

    private function finishAutoSnapshot(?array $payload = null, string $outcome = 'success'): array
    {
        $job = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['snapshot']])->assertOk()->json('data');
        $this->assertNotNull($job);
        $payload ??= $this->payload(); $payload['completeness']['equipped'] = true;
        $this->withToken($this->token)->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete', ['leaseToken' => $job['leaseToken'], 'outcome' => $outcome, 'payload' => $payload])->assertOk();
        return $job;
    }

    public function test_create_nick_queues_snapshot_then_publishes_in_category_with_attributes_and_no_duplicate(): void
    {
        [$seller, , $category, $fields] = $this->nickAttributesFixture();
        $body = ['username' => 'auto-account', 'password' => 'private-password', 'serverId' => 10, 'serverGameId' => 37, 'usageType' => 'nick', 'categoryId' => $category->id, 'price' => 12345, 'description' => 'Mô tả nick', 'attributeSelections' => [$fields['Đăng kí']['id'] => $fields['Đăng kí']['options'][1]]];
        $id = $this->actingAs($seller)->postJson('/admin/nro-shop/accounts', $body)->assertOk()->json('id');
        $this->assertDatabaseHas('nro_accounts', ['id' => $id, 'publish_status' => 'waiting_snapshot', 'auto_publish' => true]);
        $this->assertDatabaseHas('nro_worker_jobs', ['account_id' => $id, 'type' => 'snapshot', 'status' => 'queued']);
        $this->assertDatabaseCount('nicks', 0);
        $this->postJson('/admin/nro-shop/accounts/'.$id.'/scan')->assertUnprocessable();
        $job = $this->finishAutoSnapshot();
        $nick = Nick::withoutUserOwnedScope()->where('game_account_id', $id)->firstOrFail();
        $this->assertEquals($seller->id, $nick->user_id); $this->assertEquals($category->id, $nick->category_id); $this->assertEquals(12345, $nick->price);
        $this->assertDatabaseHas('nro_accounts', ['id' => $id, 'publish_status' => 'published']);
        $this->assertDatabaseHas('nick_attributes', ['nick_id' => $nick->id, 'attribute_id' => $fields['Hành Tinh']['id'], 'attribute_option_id' => $fields['Hành Tinh']['options'][0]]);
        $this->assertDatabaseHas('nick_attributes', ['nick_id' => $nick->id, 'attribute_id' => $fields['Đăng kí']['id'], 'attribute_option_id' => $fields['Đăng kí']['options'][1]]);
        $this->getJson('/api/nick/'.$nick->id)->assertOk()->assertDontSee('private-password')->assertJsonPath('data.nro_snapshot.data.character.name', 'botgame');
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete', ['leaseToken' => $job['leaseToken'], 'outcome' => 'success', 'payload' => $this->payload()])->assertOk();
        $this->assertDatabaseCount('nicks', 1);
        $this->postJson('/admin/nro-shop/accounts/'.$id.'/scan')->assertOk();
        $this->finishAutoSnapshot();
        $this->assertDatabaseCount('nicks', 1);
        $this->assertEquals(NroAccount::find($id)->latest_snapshot_id, $nick->fresh()->snapshot_id);
    }

    public function test_auto_publish_checks_permissions_category_and_foreign_attributes_before_registration(): void
    {
        [$seller, , $category, $fields] = $this->nickAttributesFixture();
        $body = ['username' => 'auto-denied', 'password' => 'private-password', 'serverId' => 10, 'serverGameId' => 37, 'usageType' => 'nick', 'categoryId' => $category->id, 'price' => 200];
        $this->actingAs($seller)->postJson('/admin/nro-shop/accounts', [...$body, 'price' => 0])->assertUnprocessable();
        $this->postJson('/admin/nro-shop/accounts', [...$body, 'attributeSelections' => [$fields['Hành Tinh']['id'] => $fields['Đăng kí']['options'][0]]])->assertUnprocessable();
        $seller->revokePermissionTo('nicks.manage');
        $this->postJson('/admin/nro-shop/accounts', $body)->assertForbidden();
        $seller->givePermissionTo('nicks.manage'); $seller->categories()->detach();
        $this->postJson('/admin/nro-shop/accounts', $body)->assertForbidden();
        $this->getJson('/admin/nro-shop/nick-attribute-fields?categoryId='.$category->id)->assertForbidden();
        $this->assertDatabaseMissing('nro_accounts', ['account_name' => 'auto-denied']);
    }

    public function test_warehouse_ignores_nick_fields_and_does_not_queue_auto_publication(): void
    {
        $seller = $this->seller(); $this->warehouse($seller); $seller->revokePermissionTo('nicks.manage');
        $id = $this->actingAs($seller)->postJson('/admin/nro-shop/accounts', ['username' => 'new-warehouse', 'password' => 'pw', 'serverId' => 10, 'serverGameId' => 37, 'usageType' => 'warehouse', 'categoryId' => 999999, 'price' => 200])->assertOk()->json('id');
        $account = NroAccount::find($id); $this->assertFalse($account->auto_publish); $this->assertNull($account->publish_config);
        $this->assertDatabaseHas('nro_worker_jobs', ['account_id' => $id, 'type' => 'snapshot', 'status' => 'queued']);
    }

    public function test_partial_snapshot_and_failed_scan_do_not_publish_and_rescan_recovers(): void
    {
        [$seller, , $category] = $this->nickAttributesFixture();
        $id = $this->actingAs($seller)->postJson('/admin/nro-shop/accounts', ['username' => 'auto-partial', 'password' => 'pw', 'serverId' => 10, 'serverGameId' => 37, 'usageType' => 'nick', 'categoryId' => $category->id, 'price' => 200])->assertOk()->json('id');
        $this->finishAutoSnapshot(null, 'failed');
        $this->assertDatabaseHas('nro_accounts', ['id' => $id, 'publish_status' => 'scan_failed']);
        $this->postJson('/admin/nro-shop/accounts/'.$id.'/scan')->assertOk();
        $this->finishAutoSnapshot($this->payload(1, false));
        $this->assertDatabaseHas('nro_accounts', ['id' => $id, 'publish_status' => 'needs_attention']);
        $this->assertNotNull(NroAccount::find($id)->latest_snapshot_id); $this->assertDatabaseCount('nicks', 0);
        $this->postJson('/admin/nro-shop/accounts/'.$id.'/scan')->assertOk();
        $this->finishAutoSnapshot();
        $this->assertDatabaseHas('nro_accounts', ['id' => $id, 'publish_status' => 'published']);
    }

    public function test_auto_publish_rechecks_category_access_when_worker_completes(): void
    {
        [$seller, , $category] = $this->nickAttributesFixture();
        $id = $this->actingAs($seller)->postJson('/admin/nro-shop/accounts', ['username' => 'auto-revoked', 'password' => 'pw', 'serverId' => 10, 'serverGameId' => 37, 'usageType' => 'nick', 'categoryId' => $category->id, 'price' => 200])->assertOk()->json('id');
        $seller->categories()->detach();
        $this->finishAutoSnapshot();
        $this->assertDatabaseHas('nro_accounts', ['id' => $id, 'publish_status' => 'needs_attention']);
        $this->assertDatabaseCount('nicks', 0);
    }

    public function test_auto_publish_copies_optional_uploaded_images_to_standard_nick_gallery(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        [$seller, , $category] = $this->nickAttributesFixture();
        $id = $this->actingAs($seller)->post('/admin/nro-shop/accounts', ['username' => 'auto-image', 'password' => 'pw', 'serverId' => 10, 'serverGameId' => 37, 'usageType' => 'nick', 'categoryId' => $category->id, 'price' => 200, 'images' => [\Illuminate\Http\UploadedFile::fake()->image('nick.png')]], ['Accept' => 'application/json'])->assertOk()->json('id');
        $this->finishAutoSnapshot();
        $nick = Nick::withoutUserOwnedScope()->where('game_account_id', $id)->firstOrFail();
        $this->assertNotEmpty($nick->image); $this->assertCount(1, $nick->getMedia('images'));
        app(\App\Services\NroAutoPublishService::class)->publish($id);
        $this->assertCount(1, $nick->fresh()->getMedia('images'));
    }

    public function test_auto_publish_stops_if_owner_loses_permission_or_is_locked_or_account_is_sold(): void
    {
        [$seller, , $category] = $this->nickAttributesFixture();
        foreach (['permission', 'locked', 'sold', 'category'] as $case) {
            $id = $this->actingAs($seller)->postJson('/admin/nro-shop/accounts', ['username' => 'auto-stop-'.$case, 'password' => 'pw', 'serverId' => 10, 'serverGameId' => 37, 'usageType' => 'nick', 'categoryId' => $category->id, 'price' => 200])->assertOk()->json('id');
            $job = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['snapshot']])->assertOk()->json('data');
            if ($case === 'permission') $seller->revokePermissionTo('nicks.manage');
            if ($case === 'locked') $seller->update(['status' => 'locked', 'locked_until' => null]);
            if ($case === 'sold') NroAccount::find($id)->update(['status' => 'sold']);
            if ($case === 'category') $category->update(['status' => 'inactive']);
            $payload = $this->payload(); $payload['completeness']['equipped'] = true;
            $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete', ['leaseToken' => $job['leaseToken'], 'outcome' => 'success', 'payload' => $payload])->assertOk();
            $this->assertDatabaseHas('nro_accounts', ['id' => $id, 'publish_status' => 'needs_attention']);
            $this->assertDatabaseCount('nicks', 0);
            $seller->givePermissionTo('nicks.manage'); $seller->update(['status' => 'active']); $category->update(['status' => 'active']);
        }
    }

    public function test_auto_publish_keeps_snapshot_when_selected_option_was_disabled_and_can_be_published_manually(): void
    {
        [$seller, , $category, $fields] = $this->nickAttributesFixture();
        $id = $this->actingAs($seller)->postJson('/admin/nro-shop/accounts', ['username' => 'auto-invalid-option', 'password' => 'pw', 'serverId' => 10, 'serverGameId' => 37, 'usageType' => 'nick', 'categoryId' => $category->id, 'price' => 200, 'attributeSelections' => [$fields['Đăng kí']['id'] => $fields['Đăng kí']['options'][1]]])->assertOk()->json('id');
        DB::table('attribute_options')->where('id', $fields['Đăng kí']['options'][1])->update(['status' => 0]);
        $this->finishAutoSnapshot();
        $this->assertDatabaseHas('nro_accounts', ['id' => $id, 'publish_status' => 'needs_attention']); $this->assertDatabaseCount('nicks', 0);
        $this->getJson('/admin/nro-shop/accounts/'.$id)->assertOk()->assertJsonPath('publishConfig.price', 200);
        $nick = $this->postJson('/admin/nro-shop/accounts/'.$id.'/nick', ['categoryId' => $category->id, 'price' => 300, 'attributeSelections' => [$fields['Đăng kí']['id'] => $fields['Đăng kí']['options'][0]]])->assertOk()->json('id');
        $this->assertDatabaseHas('nro_accounts', ['id' => $id, 'publish_status' => 'published', 'auto_publish' => false]);
        app(\App\Services\NroAutoPublishService::class)->publish($id);
        $this->assertDatabaseHas('nicks', ['id' => $nick, 'price' => 300]); $this->assertDatabaseCount('nicks', 1);
    }

    public function test_complete_stock_does_not_trigger_periodic_scan_but_invalid_stock_is_refreshed_automatically(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller);
        $payload = $this->payload(); $payload['snapshot']['capturedAt'] = now()->subDays(3)->toIso8601String();
        app(NroSnapshotService::class)->ingest($a, $payload);
        $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['snapshot']])->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseCount('nro_worker_jobs', 0);
        $a->update(['last_synced_at' => null]);
        $job = $this->finishAutoSnapshot();
        $this->assertEquals($a->id, $job['account']['id']); $this->assertNotNull($a->fresh()->last_synced_at);
        app(NroSnapshotService::class)->ingest($a, $this->payload(1, false));
        $this->assertNull($a->fresh()->last_synced_at);
        $this->travel(3)->minutes();
        $this->finishAutoSnapshot(); $this->assertNotNull($a->fresh()->last_synced_at);
    }

    public function test_bulk_preview_import_resolves_ids_and_names_hides_passwords_and_reports_each_line(): void
    {
        [$seller, , $category, $fields] = $this->nickAttributesFixture();
        $text = "\xEF\xBB\xBFbatch-nick|SECRET_BATCH_PASSWORD|10|37|0|{$category->id}|150000|\"Mô tả | có dấu\"|https://images.example.com/nick.png\r\nbatch-kho|SECRET_WAREHOUSE_PASSWORD|Vũ Trụ 12|Login test|1\r\nbatch-nick|DIFFERENT_SECRET|10|37|0|{$category->id}|100\r\nbad|SECRET_BAD|10|37|5";
        $base = '/admin/nro-shop/accounts/import';
        $this->actingAs($seller)->postJson($base, ['text' => $text, 'mode' => 'preview'])->assertOk()->assertJsonPath('valid', 2)->assertJsonPath('invalid', 2)
            ->assertJsonPath('rows.0.categoryName', $category->name)->assertJsonPath('rows.1.usageType', 'warehouse')->assertDontSee('SECRET_BATCH_PASSWORD')->assertDontSee('SECRET_WAREHOUSE_PASSWORD')->assertDontSee('SECRET_BAD');
        $this->assertDatabaseMissing('nro_accounts', ['account_name' => 'batch-nick']); $this->assertDatabaseCount('nro_worker_jobs', 0);
        $result = $this->postJson($base, ['text' => $text, 'mode' => 'import'])->assertOk()->assertJsonPath('valid', 2)->assertJsonPath('invalid', 2)->assertDontSee('SECRET_BATCH_PASSWORD')->json();
        $account = NroAccount::find($result['rows'][0]['accountId']);
        $this->assertSame('SECRET_BATCH_PASSWORD', $account->game_password);
        $this->assertSame('Mô tả | có dấu', $account->publish_config['description']);
        $this->assertEquals($fields['Đăng kí']['options'][0], $account->publish_config['attributeSelections'][$fields['Đăng kí']['id']]);
        $this->assertDatabaseCount('nro_worker_jobs', 2);
        $this->finishAutoSnapshot();
        $nick = Nick::withoutUserOwnedScope()->where('game_account_id', $account->id)->firstOrFail();
        $this->assertSame('https://images.example.com/nick.png', $nick->image);
        $this->getJson('/api/nick/'.$nick->id)->assertOk()->assertJsonPath('data.images.0.url', $nick->image)->assertDontSee('SECRET_BATCH_PASSWORD');
        $this->assertDatabaseHas('nick_attributes', ['nick_id' => $nick->id, 'attribute_id' => $fields['Đăng kí']['id'], 'attribute_option_id' => $fields['Đăng kí']['options'][0]]);
        $this->postJson($base, ['text' => $text, 'mode' => 'import'])->assertOk()->assertJsonPath('valid', 0);
        $this->assertDatabaseCount('nro_worker_jobs', 2);
    }

    public function test_bulk_enforces_category_permission_and_rejects_ambiguous_names_bad_urls_and_limits(): void
    {
        [$seller, , $category] = $this->nickAttributesFixture();
        $base = '/admin/nro-shop/accounts/import';
        $nick = "bulk-denied|secret|10|37|0|{$category->id}|100";
        $seller->categories()->detach();
        $this->actingAs($seller)->postJson($base, ['text' => $nick, 'mode' => 'import'])->assertOk()->assertJsonPath('valid', 0);
        $seller->categories()->attach($category->id, ['can_post' => true]); $seller->revokePermissionTo('nicks.manage');
        $this->postJson($base, ['text' => $nick."\nkho-only|secret|10|37|1", 'mode' => 'import'])->assertOk()->assertJsonPath('valid', 1)->assertJsonPath('invalid', 1);
        $seller->givePermissionTo('nicks.manage');
        $this->postJson($base, ['text' => $nick.'||javascript:alert(1)', 'mode' => 'preview'])->assertOk()->assertJsonPath('valid', 0);
        DB::table('servers')->insert(['id' => 11, 'name' => 'other', 'name_view' => 'Vũ Trụ 12', 'status' => true]);
        $this->postJson($base, ['text' => 'ambiguous|secret|Vũ Trụ 12|37|1', 'mode' => 'preview'])->assertOk()->assertJsonPath('valid', 0);
        $this->postJson($base, ['text' => str_repeat("a|b|10|37|1\n", 201), 'mode' => 'preview'])->assertUnprocessable();
        $seller->revokePermissionTo('nro-accounts.manage');
        $this->postJson($base, ['text' => $nick, 'mode' => 'import'])->assertForbidden();
        $this->assertDatabaseMissing('nro_accounts', ['account_name' => 'bulk-denied']);
    }

    public function test_registration_default_uses_category_option_id_and_skips_missing_or_disabled_ao(): void
    {
        [$seller, , $category, $fields] = $this->nickAttributesFixture();
        $url = '/admin/nro-shop/nick-attribute-fields?categoryId='.$category->id;
        $get = fn () => collect($this->actingAs($seller)->getJson($url)->assertOk()->json('fields'))->firstWhere('id', $fields['Đăng kí']['id']);
        $this->assertSame($fields['Đăng kí']['options'][0], $get()['selectedId']);
        DB::table('attribute_options')->where('id', $fields['Đăng kí']['options'][0])->update(['status' => 0]);
        $this->assertNull($get()['selectedId']);
        $category->attributes()->detach($fields['Đăng kí']['id']);
        $this->assertNull($get());
    }

    public function test_admin_account_pagination_filters_and_counts_stay_scoped_to_ctv(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller);
        foreach (range(1, 34) as $i) { $copy = $a->replicate(); $copy->account_name = 'page-acc-'.$i; $copy->latest_snapshot_id = null; $copy->save(); }
        $this->warehouse($this->seller());
        $this->actingAs($seller)->get('/admin/nro-shop')->assertOk()->assertInertia(fn ($p) => $p->has('accounts', 30)->where('accountStats.total', 35)->where('accountPagination.total', 35));
        $this->get('/admin/nro-shop?page=2')->assertOk()->assertInertia(fn ($p) => $p->has('accounts', 5)->where('accountPagination.current', 2));
        $this->get('/admin/nro-shop?q=page-acc-34&usage=warehouse&server=10')->assertOk()->assertInertia(fn ($p) => $p->has('accounts', 1)->where('accounts.0.account_name', 'page-acc-34')->where('accountStats.total', 35));
        $this->get('/admin/nro-shop?usage=nick')->assertOk()->assertInertia(fn ($p) => $p->has('accounts', 0)->where('accountPagination.total', 0));
    }

    public function test_reviewed_warehouses_do_not_starve_other_automatic_stock_refreshes(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller);
        foreach (range(1, 10) as $i) {
            $blocked = $a->replicate(); $blocked->account_name = 'blocked-'.$i; $blocked->last_synced_at = null; $blocked->save();
            DB::table('nro_worker_jobs')->insert(['account_id' => $blocked->id, 'type' => 'snapshot', 'status' => 'review', 'created_at' => now(), 'updated_at' => now()]);
        }
        $target = $a->replicate(); $target->account_name = 'refresh-target'; $target->last_synced_at = null; $target->save();
        $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['snapshot']])->assertOk()->assertJsonPath('data.account.id', $target->id);
    }

    public function test_edit_paused_nick_rescans_and_republishes_same_id_and_rejects_foreign_sold_and_busy_accounts(): void
    {
        [$seller, , $category] = $this->nickAttributesFixture();
        $id = $this->actingAs($seller, 'web')->postJson('/admin/nro-shop/accounts', ['username' => 'edit-original', 'password' => 'secret', 'serverId' => 10, 'serverGameId' => 37, 'usageType' => 'nick', 'categoryId' => $category->id, 'price' => 500])->assertOk()->json('id');
        $this->finishAutoSnapshot();
        $nick = Nick::withoutUserOwnedScope()->where('game_account_id', $id)->firstOrFail();
        $nick->update(['status' => 'deleted']);
        DB::table('server_game_login')->insert(['id' => 38, 'name' => 'Other login', 'ip' => '127.0.0.1', 'port' => '14445']);
        $body = ['username' => 'edit-corrected', 'serverId' => 10, 'serverGameId' => 38];
        $this->actingAs($this->seller(), 'web')->patchJson('/admin/nro-shop/accounts/'.$id, $body)->assertNotFound();
        $this->actingAs($seller, 'web')->patchJson('/admin/nro-shop/accounts/'.$id, $body)->assertOk();
        $this->assertDatabaseHas('nicks', ['id' => $nick->id, 'status' => 'deleted', 'snapshot_id' => null]);
        $this->assertNull(NroAccount::find($id)->latest_snapshot_id);
        $this->assertSame('secret', NroAccount::find($id)->game_password);
        $this->patchJson('/admin/nro-shop/accounts/'.$id, $body)->assertUnprocessable();
        $this->finishAutoSnapshot();
        $this->assertDatabaseHas('nicks', ['id' => $nick->id, 'status' => 'not_sold', 'account_name' => 'edit-corrected']);
        $this->assertSame(1, Nick::withoutUserOwnedScope()->where('game_account_id', $id)->count());
        $this->assertArrayNotHasKey('resumeNickId', NroAccount::find($id)->publish_config);
        $nick->refresh()->update(['status' => 'sold']);
        $this->actingAs($seller, 'web')->patchJson('/admin/nro-shop/accounts/'.$id, $body)->assertUnprocessable();
    }

    /** Count the queries one callable issues. */
    private function queriesFor(callable $fn): int
    {
        DB::flushQueryLog(); DB::enableQueryLog();
        try { $fn(); return count(DB::getQueryLog()); } finally { DB::disableQueryLog(); }
    }

    public function test_listing_and_order_batches_do_not_grow_queries_with_row_count(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller);
        $inventory = DB::table('nro_inventory_items')->where('account_id', $a->id)->orderBy('id')->get();
        foreach (range(1, 24) as $n) {
            $id = DB::table('item_listings')->insertGetId(['user_id' => $seller->id, 'account_id' => $a->id,
                'title' => 'Gói '.$n, 'description' => '', 'price' => $n * 100, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('item_listing_items')->insert(['listing_id' => $id, 'inventory_item_id' => $inventory[0]->id, 'quantity' => 1]);
        }
        $rows = DB::table('item_listings')->where('account_id', $a->id)->orderBy('id')->get();
        $shop = app(NroShopService::class);
        \App\Services\NroListingStock::policy(); // Warm the settings cache so it is not counted once.

        $few = $this->queriesFor(fn () => $shop->listings($rows->take(3)));
        $many = $this->queriesFor(fn () => $shop->listings($rows));
        $this->assertSame($few, $many, 'listings() must issue the same number of queries for 3 rows as for 24.');
        $this->assertLessThanOrEqual(12, $many);
        $this->assertCount($rows->count(), $shop->listings($rows));
    }

    public function test_admin_index_does_not_build_the_other_tabs(): void
    {
        $seller = $this->seller(); $seller->givePermissionTo(['item-orders.view', 'item-orders.reconcile', 'nro-workers.manage']);
        $a = $this->warehouse($seller);
        $inventory = DB::table('nro_inventory_items')->where('account_id', $a->id)->orderBy('id')->get();
        foreach (range(1, 24) as $n) {
            $id = DB::table('item_listings')->insertGetId(['user_id' => $seller->id, 'account_id' => $a->id,
                'title' => 'Gói '.$n, 'description' => '', 'price' => $n * 100, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('item_listing_items')->insert(['listing_id' => $id, 'inventory_item_id' => $inventory[0]->id, 'quantity' => 1]);
        }

        $response = null;
        $queries = $this->queriesFor(function () use ($seller, &$response) {
            $response = $this->actingAs($seller, 'web')->get('/admin/nro-shop');
        });
        $response->assertOk();
        $props = $response->viewData('page')['props'];
        foreach (['listings', 'orders', 'jobs', 'workerKeys'] as $tab) {
            $this->assertArrayNotHasKey($tab, $props, "The page must not build the '$tab' tab; it has its own endpoint.");
        }
        $this->assertLessThanOrEqual(40, $queries, 'The accounts page grew a per-row query.');

        // Each tab is reachable on its own and paginates.
        $this->actingAs($seller, 'web')->getJson('/admin/nro-shop/listings')->assertOk()
            ->assertJsonPath('total', 24)->assertJsonCount(20, 'data');
        $this->actingAs($seller, 'web')->getJson('/admin/nro-shop/listings?page=2')->assertOk()->assertJsonCount(4, 'data');
        $this->actingAs($seller, 'web')->getJson('/admin/nro-shop/listings?status=paused')->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($seller, 'web')->getJson('/admin/nro-shop/orders')->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($seller, 'web')->getJson('/admin/nro-shop/jobs')->assertOk();
        $this->actingAs($seller, 'web')->getJson('/admin/nro-shop/worker-keys')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($seller, 'web')->getJson('/admin/nro-shop/status')->assertOk()
            ->assertJsonPath('warehouse', 1)->assertJsonPath('reviewJobs', 0);
    }
    public function test_shared_worker_claims_many_orders_and_suspends_only_timed_out_customer(): void
    {
        $seller = $this->seller(); $account = $this->warehouse($seller);
        $listing1 = $this->listing($seller, $account); $listing2 = $this->listing($seller, $account);
        $buyer = User::factory()->create(['balance' => 2000]); $shop = app(NroShopService::class);
        $receive = app(\App\Services\NroReceivingService::class);
        $orders = [];
        foreach ([$listing1, $listing2] as $n => $listing) {
            $orders[] = $id = $shop->purchase($buyer, $listing, 'customer'.$n, 10, (string) Str::uuid());
            $receive->start($buyer, $id, ['mode' => 'manual', 'recipientName' => 'customer'.$n, 'requestKey' => (string) Str::uuid()]);
        }
        $instance = (string) Str::uuid();
        $claim = ['protocolVersion' => 4, 'workerInstance' => $instance, 'types' => ['delivery']];
        $first = $this->withToken($this->token)->postJson('/app/nro-worker/claim', $claim)->assertOk()->json('data');
        $this->postJson('/app/nro-worker/claim', [...$claim, 'workerInstance' => (string) Str::uuid()])->assertOk()->assertJsonPath('data', null);
        $this->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk()->assertJsonPath('data', null);
        $second = $this->postJson('/app/nro-worker/claim', [...$claim, 'allowNewAccount' => false, 'activeAccountIds' => [$account->id]])->assertOk()->json('data');
        $this->assertNotEquals($first['id'], $second['id']);
        $this->assertEquals($first['account']['id'], $second['account']['id']);
        foreach ([$first, $second] as $n => $job) $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/ready', [
            'leaseToken' => $job['leaseToken'], 'characterId' => 10, 'name' => 'shared-bot', 'mapId' => 5, 'zone' => 7, 'recipientName' => 'customer'.$n,
        ])->assertOk();
        $url1 = '/app/nro-worker/jobs/'.$first['id']; $url2 = '/app/nro-worker/jobs/'.$second['id'];
        $this->postJson($url1.'/begin-round', ['leaseToken' => $first['leaseToken']])->assertOk();
        $this->postJson($url2.'/begin-round', ['leaseToken' => $second['leaseToken']])->assertConflict();
        $this->assertTrue($shop->order($orders[1])['botActivity']['servingOther']);
        $this->assertArrayNotHasKey('buyerUsername', $shop->order($orders[1]));
        $this->postJson($url1.'/trade-phase', ['leaseToken' => $first['leaseToken'], 'phase' => 'confirming'])->assertOk();
        $this->postJson($url1.'/complete', ['leaseToken' => $first['leaseToken'], 'outcome' => 'trade_paused', 'message' => 'Timeout', 'payload' => $this->payload()])->assertOk();
        $this->assertDatabaseHas('item_orders', ['id' => $orders[0], 'status' => 'awaiting_receipt']);
        $this->assertEquals('suspended', $shop->order($orders[0])['session']['status']);
        try {
            $receive->start($buyer, $orders[0], ['mode' => 'manual', 'recipientName' => 'customer0', 'requestKey' => (string) Str::uuid()]);
            $this->fail('Cooldown should prevent another receiving session');
        } catch (\Illuminate\Validation\ValidationException $e) { $this->assertStringContainsString('tạm dừng', $e->getMessage()); }
        $this->postJson($url2.'/begin-round', ['leaseToken' => $second['leaseToken']])->assertOk();
        $progress = ['leaseToken' => $second['leaseToken'], 'items' => array_map(fn ($i) => ['id' => $i['id'], 'delivered' => 1], $second['order']['items'])];
        $this->postJson($url2.'/progress', [...$progress, 'payload' => $this->payload(1)])->assertOk();
        $this->assertEquals([1, 1], DB::table('nro_inventory_items')->where('account_id', $account->id)->orderBy('id')->pluck('quantity')->all());
        // A repeated progress body carrying old stock must not resurrect the delivered items.
        $this->postJson($url2.'/progress', [...$progress, 'payload' => $this->payload(2)])->assertOk();
        $this->assertEquals([1, 1], DB::table('nro_inventory_items')->where('account_id', $account->id)->orderBy('id')->pluck('quantity')->all());
        $this->postJson($url2.'/complete', ['leaseToken' => $second['leaseToken'], 'outcome' => 'success', 'payload' => $this->payload(1)])->assertOk();
        $this->assertEquals(1600, $buyer->fresh()->balance);
        $this->assertEquals(200, $seller->fresh()->balance);
        $this->travel(6)->minutes();
        $receive->start($buyer, $orders[0], ['mode' => 'manual', 'recipientName' => 'customer0', 'requestKey' => (string) Str::uuid()]);
        $this->assertEquals(1600, $buyer->fresh()->balance);
    }

    public function test_listing_price_edit_is_owned_and_does_not_change_purchased_order(): void
    {
        $seller = $this->seller(); $account = $this->warehouse($seller); $listing = $this->listing($seller, $account);
        $this->actingAs($seller, 'web')->patchJson('/admin/nro-shop/listings/'.$listing, ['price' => 350])->assertOk();
        $this->assertDatabaseHas('item_listings', ['id' => $listing, 'price' => 350, 'status' => 'active']);
        $foreign = $this->seller();
        $this->actingAs($foreign, 'web')->patchJson('/admin/nro-shop/listings/'.$listing, ['price' => 1])->assertNotFound();
        $buyer = User::factory()->create(['balance' => 1000]);
        $order = app(NroShopService::class)->purchase($buyer, $listing, 'customer', 10, (string) Str::uuid());
        $this->actingAs($seller, 'web')->patchJson('/admin/nro-shop/listings/'.$listing, ['price' => 999])->assertUnprocessable();
        $this->assertDatabaseHas('item_orders', ['id' => $order, 'price' => 350]);
    }


    public function test_warehouse_trip_freezes_all_waiters_and_updates_zone_without_changing_recipients(): void
    {
        $seller = $this->seller(); $account = $this->warehouse($seller);
        $buyer = User::factory()->create(['balance' => 2000]); $shop = app(NroShopService::class);
        $instance = (string) Str::uuid(); $jobs = []; $expires = []; $orders = [];
        foreach (['first', 'second'] as $name) {
            $listing = $this->listing($seller, $account);
            $orders[] = $order = $shop->purchase($buyer, $listing, $name, 10, (string) Str::uuid());
            app(\App\Services\NroReceivingService::class)->start($buyer, $order, ['mode'=>'manual','recipientName'=>$name,'requestKey'=>(string) Str::uuid()]);
            $job = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion'=>4,'workerInstance'=>$instance,'types'=>['delivery']])->assertOk()->json('data');
            $jobs[] = $job;
            $expires[] = $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/ready', ['leaseToken'=>$job['leaseToken'],'characterId'=>10,'name'=>'bot','mapId'=>5,'zone'=>7,'recipientName'=>$name])->assertOk()->json('expiresAt');
        }
        $url = '/app/nro-worker/jobs/'.$jobs[0]['id']; $lease = ['leaseToken'=>$jobs[0]['leaseToken']];
        $this->postJson($url.'/warehouse-state', [...$lease,'phase'=>'home'])->assertOk();
        $this->travel(60)->seconds();
        $this->postJson($url.'/warehouse-state', [...$lease,'phase'=>'collecting'])->assertOk();
        $public = $shop->order($orders[1])['botActivity'];
        $this->assertTrue($public['preparing']); $this->assertEquals('collecting', $public['phase']);
        $this->assertArrayNotHasKey('workerInstance', $public);
        $this->postJson($url.'/begin-round', $lease)->assertConflict();
        $this->postJson($url.'/ready', [...$lease,'characterId'=>10,'name'=>'bot','mapId'=>5,'zone'=>7,'recipientName'=>'first'])->assertOk()->assertJson(fn ($json) => $json->where('remainingSeconds', fn ($v) => $v > 1798)->etc());
        $this->postJson($url.'/warehouse-state', [...$lease,'phase'=>'ready','position'=>['characterId'=>10,'name'=>'bot','mapId'=>5,'mapName'=>'Đảo Kame','zone'=>12,'x'=>285,'y'=>288]])->assertOk();
        foreach ($orders as $n=>$order) {
            $data = $shop->order($order);
            $this->assertFalse($data['botActivity']['preparing']);
            $this->assertEquals(12, $data['session']['position']['zone']);
            $this->assertEquals(['first','second'][$n], $data['session']['position']['recipientName']);
            $this->assertEqualsWithDelta(60, \Carbon\Carbon::parse($expires[$n])->diffInSeconds(\Carbon\Carbon::parse($data['session']['expiresAt']), false), 2);
        }
        $this->postJson($url.'/begin-round', $lease)->assertOk();
        $this->postJson($url.'/warehouse-state', [...$lease,'phase'=>'home'])->assertConflict();
        $deadline = DB::table('nro_delivery_sessions')->where('id',$jobs[0]['receiving']['id'])->value('phase_deadline');
        $this->assertEqualsWithDelta(20, now()->diffInSeconds(\Carbon\Carbon::parse($deadline),false), 2);
        DB::table('nro_worker_jobs')->update(['lease_until'=>now()->subSecond()]);
        $this->assertNull($shop->order($orders[1])['botActivity']['phase']);
    }

    public function test_manual_same_recipient_waits_for_previous_order_without_starting_clock(): void
    {
        $seller=$this->seller(); $a=$this->warehouse($seller); $buyer=User::factory()->create(['balance'=>2000]);
        $jobs=[]; $instance=(string) Str::uuid();
        foreach (range(1,2) as $n) {
            $order=app(NroShopService::class)->purchase($buyer,$this->listing($seller,$a),'same',10,(string) Str::uuid());
            app(\App\Services\NroReceivingService::class)->start($buyer,$order,['mode'=>'manual','recipientName'=>'same','requestKey'=>(string) Str::uuid()]);
            $jobs[]=$this->withToken($this->token)->postJson('/app/nro-worker/claim',['protocolVersion'=>4,'workerInstance'=>$instance,'types'=>['delivery']])->assertOk()->json('data');
        }
        $body=['characterId'=>10,'name'=>'bot','mapId'=>5,'zone'=>7,'recipientName'=>'same'];
        $this->postJson('/app/nro-worker/jobs/'.$jobs[0]['id'].'/ready',[...$body,'leaseToken'=>$jobs[0]['leaseToken']])->assertOk();
        $this->postJson('/app/nro-worker/jobs/'.$jobs[1]['id'].'/ready',[...$body,'leaseToken'=>$jobs[1]['leaseToken']])->assertStatus(202)->assertJsonPath('waitingForRecipient',true);
        $this->assertNull(DB::table('nro_delivery_sessions')->where('id',$jobs[1]['receiving']['id'])->value('expires_at'));
        $this->postJson('/app/nro-worker/jobs/'.$jobs[0]['id'].'/complete',['leaseToken'=>$jobs[0]['leaseToken'],'outcome'=>'login_failed','message'=>'Recipient left'])->assertOk();
        $this->postJson('/app/nro-worker/jobs/'.$jobs[1]['id'].'/ready',[...$body,'leaseToken'=>$jobs[1]['leaseToken']])->assertOk()->assertJson(fn ($json)=>$json->where('remainingSeconds',fn($v)=>$v>1798)->etc());
    }


    public function test_item_filters_match_one_item_in_combo_and_old_inventory_is_backfilled(): void
    {
        $seller=$this->seller(); $account=$this->warehouse($seller);
        $catalog=collect(json_decode(file_get_contents(resource_path('nro/item-templates.json')),true));
        $glove=$catalog->firstWhere('type',2)['id'];
        $payload=$this->payload();
        $payload['snapshot']['bag'][0]['options']=[['optionId'=>6,'param'=>100],['optionId'=>107,'param'=>2]];
        $payload['snapshot']['bag'][1]['templateId']=$glove;
        $payload['snapshot']['bag'][1]['options']=[['optionId'=>0,'param'=>150],['optionId'=>107,'param'=>7]];
        // Use a fresh warehouse inventory to avoid including historic zero-quantity rows in the helper.
        DB::table('nro_inventory_items')->where('account_id',$account->id)->delete();
        app(NroSnapshotService::class)->ingest($account,$payload);
        $id=$this->listing($seller,$account);
        $url='/api/nro-shop/listings?group=equipment&bundle=combo';
        $this->getJson($url.'&equipmentType=2&minStars=5&stat=damage')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$id)->assertJsonPath('from',1)->assertJsonPath('to',1);
        $this->getJson($url.'&equipmentType=0&minStars=5')->assertOk()->assertJsonPath('total',0);
        $this->getJson($url.'&equipmentType=2&stat=hp')->assertOk()->assertJsonPath('total',0);
        $this->getJson($url.'&q=0&equipmentType=2')->assertOk()->assertJsonPath('total',0);
        $this->getJson('/api/nro-shop/listings?group=dragon_balls')->assertOk()->assertJsonPath('total',0);
        $this->getJson('/api/nro-shop/listings?group=invalid')->assertUnprocessable();
        $this->getJson('/api/nro-shop/listings?group=crystals&minStars=5')->assertUnprocessable();
        $this->getJson('/api/nro-shop/listings?group=equipment&minStars=10')->assertUnprocessable();
        $migration=require database_path('migrations/2026_09_10_000004_add_nro_inventory_filters.php');
        $migration->down(); $migration->up();
        $this->assertDatabaseHas('nro_inventory_items',['account_id'=>$account->id,'template_id'=>$glove,'filter_stars'=>7,'filter_damage'=>true,'filter_hp'=>false]);
        $this->assertDatabaseHas('nro_inventory_items',['account_id'=>$account->id,'template_id'=>0,'filter_stars'=>2,'filter_hp'=>true]);
    }

    public function test_classification_override_is_permission_guarded_and_does_not_change_sale_policy(): void
    {
        $seller=$this->seller(); $a=$this->warehouse($seller); $id=$this->listing($seller,$a);
        $body=['enabled'=>false,'ids'=>[],'groupOverrides'=>[['id'=>0,'group'=>'other']]];
        $this->actingAs($seller,'web')->patchJson('/admin/nro-shop/sale-policy',$body)->assertForbidden();
        $seller->givePermissionTo('nro-sale-policy.manage');
        $this->actingAs($seller,'web')->patchJson('/admin/nro-shop/sale-policy',$body)->assertOk();
        $this->getJson('/api/nro-shop/listings?group=equipment')->assertOk()->assertJsonPath('total',0);
        $this->getJson('/api/nro-shop/listings?group=other')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$id);
        $this->assertTrue(\App\Services\NroListingStock::allows(0));
        // Existing clients saving only the allow-list preserve classification overrides.
        $this->patchJson('/admin/nro-shop/sale-policy',['enabled'=>false,'ids'=>[]])->assertOk();
        $this->assertCount(1,\App\Services\NroItemFilters::overrides());
        $this->patchJson('/admin/nro-shop/sale-policy',[...$body,'groupOverrides'=>[['id'=>99999,'group'=>'other']]])->assertUnprocessable();
        $this->patchJson('/admin/nro-shop/sale-policy',[...$body,'groupOverrides'=>[['id'=>0,'group'=>'other'],['id'=>0,'group'=>'equipment']]])->assertUnprocessable();
        $this->patchJson('/admin/nro-shop/sale-policy',[...$body,'groupOverrides'=>[]])->assertOk();
        $this->getJson('/api/nro-shop/listings?group=equipment')->assertOk()->assertJsonPath('total',1);
    }

    public function test_catalog_groups_and_unknown_star_data_are_not_guessed(): void
    {
        $filters=app(\App\Services\NroItemFilters::class);
        $this->assertSame('other',$filters->group(['id'=>-1,'name'=>'Ngọc Rồng Namek 1 sao','type'=>11]));
        $this->assertSame('other',$filters->group(['id'=>-1,'name'=>'Đứa bé','type'=>11]));
        $this->assertSame('equipment',$filters->group(['id'=>-1,'name'=>'Áo','type'=>0]));
        $this->assertSame('other',$filters->group(['id'=>-1,'name'=>'Sao pha lê','type'=>30]));
        $unknown=\App\Services\NroItemFilters::inventoryColumns(['options'=>[]]);
        $this->assertNull($unknown['filter_stars']);
        $this->assertFalse($unknown['filter_damage']);
        $this->assertFalse(\App\Services\NroItemFilters::inventoryColumns(['options'=>[['optionId'=>95,'param'=>10]]])['filter_hp']);
        $this->assertSame(7,\App\Services\NroItemFilters::inventoryColumns(['options'=>[['optionId'=>102,'param'=>3],['optionId'=>107,'param'=>7]]])['filter_stars']);
    }
    public function test_hidden_warehouse_blocks_new_sales_and_preserves_existing_delivery(): void
    {
        $seller = $this->seller(); $a = $this->warehouse($seller); $sold = $this->listing($seller, $a);
        $buyer = User::factory()->create(['balance' => 1000]);
        $order = app(NroShopService::class)->purchase($buyer, $sold, 'khach', 10, (string) Str::uuid());
        $available = $this->listing($seller, $a);
        // Prime both caches before hiding.
        $this->getJson('/api/nro-shop/listings')->assertJsonPath('total', 1);
        $this->getJson('/api/nro-shop/listings/'.$available)->assertOk();
        $this->actingAs($seller, 'web')->patchJson('/admin/nro-shop/accounts/'.$a->id.'/visibility', ['hidden' => true])->assertOk();
        $this->assertDatabaseHas('nro_accounts', ['id' => $a->id, 'shop_hidden' => true, 'status' => 'active']);
        $this->assertDatabaseHas('item_listings', ['id' => $available, 'status' => 'active']);
        $this->getJson('/api/nro-shop/listings')->assertJsonPath('total', 0);
        $this->getJson('/api/nro-shop/listings/'.$available)->assertNotFound();
        Passport::actingAs($buyer);
        $this->postJson('/api/nro-shop/orders', ['listingId' => $available, 'serverId' => 10, 'requestKey' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertEquals(800, $buyer->fresh()->balance);
        $this->assertDatabaseCount('item_orders', 1);
        app(\App\Services\NroReceivingService::class)->start($buyer, $order, ['mode' => 'auto', 'username' => 'receiver', 'password' => 'test-only', 'requestKey' => (string) Str::uuid()]);
        $job = $this->withToken($this->token)->postJson('/app/nro-worker/claim', ['protocolVersion' => 3, 'types' => ['delivery']])->assertOk()->json('data');
        $this->assertNotNull($job);
        $this->assertDatabaseHas('nro_worker_jobs', ['id' => $job['id'], 'order_id' => $order, 'status' => 'processing']);
        // Unhide must not republish sold or manually paused listings.
        DB::table('item_listings')->where('id', $available)->update(['status' => 'paused']);
        $this->actingAs($seller, 'web')->patchJson('/admin/nro-shop/accounts/'.$a->id.'/visibility', ['hidden' => false])->assertOk();
        $this->assertDatabaseHas('item_listings', ['id' => $sold, 'status' => 'sold']);
        $this->getJson('/api/nro-shop/listings')->assertJsonPath('total', 0);
        DB::table('item_listings')->where('id', $available)->update(['status' => 'active']);
        $this->patchJson('/admin/nro-shop/accounts/'.$a->id.'/visibility', ['hidden' => false])->assertOk();
        $this->getJson('/api/nro-shop/listings')->assertJsonPath('total', 1);
        $this->getJson('/api/nro-shop/listings/'.$available)->assertOk();
    }

    public function test_warehouse_visibility_enforces_permission_ownership_and_type(): void
    {
        $owner = $this->seller(); $a = $this->warehouse($owner); $foreign = $this->seller();
        $url = '/admin/nro-shop/accounts/'.$a->id.'/visibility';
        $this->actingAs($foreign)->patchJson($url, ['hidden' => true])->assertNotFound();
        $viewer = User::factory()->create(); $viewer->assignRole('ctv'); $viewer->givePermissionTo('nro-accounts.view');
        $this->actingAs($viewer)->patchJson($url, ['hidden' => true])->assertForbidden();
        $this->actingAs($owner)->patchJson($url, ['hidden' => 'invalid'])->assertUnprocessable();
        $a->update(['usage_type' => 'nick']);
        $this->patchJson($url, ['hidden' => true])->assertUnprocessable();
        $this->assertFalse($a->fresh()->shop_hidden);
    }

    public function test_exact_item_groups_and_extra_stat_flags(): void
    {
        $f = app(\App\Services\NroItemFilters::class);
        foreach ([220=>'upgrade_stones',224=>'upgrade_stones',441=>'crystals',447=>'crystals',14=>'dragon_balls',20=>'dragon_balls'] as $id=>$group) {
            $this->assertSame($group, $f->group(['id'=>$id,'name'=>'', 'type'=>99]));
        }
        $this->assertSame('equipment', $f->group(['id'=>-1,'type'=>2]));
        $flags = $f::extraInventoryColumns(['options'=>[['optionId'=>95,'param'=>10],['optionId'=>96,'param'=>0],['optionId'=>100,'param'=>20],['optionId'=>14,'param'=>5]]]);
        $this->assertTrue($flags['filter_life_steal']);
        $this->assertFalse($flags['filter_ki_steal']);
        $this->assertTrue($flags['filter_gold']);
        $this->assertTrue($flags['filter_other']);
    }

    public function test_extra_filter_migration_resumes_after_partial_ddl_without_touching_stock(): void
    {
        $a = $this->warehouse($this->seller());
        $item = DB::table('nro_inventory_items')->where('account_id', $a->id)->first();
        $json = json_encode(['options' => [
            ['optionId'=>107,'param'=>5], ['optionId'=>102,'param'=>0],
            ['optionId'=>50,'param'=>23], ['optionId'=>95,'param'=>10],
            ['optionId'=>96,'param'=>0], ['optionId'=>100,'param'=>20], ['optionId'=>14,'param'=>3],
        ]]);
        DB::table('nro_inventory_items')->where('id', $item->id)->update(['item_json'=>$json, 'reserved'=>1]);
        $migration = require database_path('migrations/2026_09_10_000005_add_nro_extra_stat_filters.php');
        $count = DB::table('nro_inventory_items')->count();
        // Failed after all four columns, failed after one column, and a fresh run.
        foreach (['all', 'partial', 'none'] as $state) {
            if ($state !== 'all') {
                $migration->down();
                if ($state === 'partial') \Illuminate\Support\Facades\Schema::table('nro_inventory_items', fn ($t) => $t->boolean('filter_life_steal')->default(false));
            }
            $migration->up();
            $migration->up(); // Repeating a backfill must also be safe.
            $this->assertDatabaseCount('nro_inventory_items', $count);
            $this->assertDatabaseHas('nro_inventory_items', [
                'id'=>$item->id, 'account_id'=>$a->id, 'quantity'=>$item->quantity, 'reserved'=>1, 'item_json'=>$json,
                'filter_stars'=>5, 'filter_damage'=>true, 'filter_hp'=>false, 'filter_ki'=>false,
                'filter_life_steal'=>true, 'filter_ki_steal'=>false, 'filter_gold'=>true, 'filter_other'=>true,
            ]);
        }
    }


    private function controlsFixture(): array
    {
        $seller=$this->seller(); $a=$this->warehouse($seller); $listing=$this->listing($seller,$a);
        $buyer=User::factory()->create(['balance'=>1000]);
        $order=app(NroShopService::class)->purchase($buyer,$listing,'khach',10,(string)Str::uuid());
        app(\App\Services\NroReceivingService::class)->start($buyer,$order,['mode'=>'manual','recipientName'=>'khach','requestKey'=>(string)Str::uuid()]);
        $job=$this->withToken($this->token)->postJson('/app/nro-worker/claim',['protocolVersion'=>3,'types'=>['delivery']])->assertOk()->json('data');
        return [$seller,$a,$buyer,$order,$job];
    }

    public function test_admin_refund_requires_closed_session_and_explicit_partial_amount_and_replays_once(): void
    {
        [$seller,$a,$buyer,$order,$job]=$this->controlsFixture();
        $admin=User::factory()->create(); $admin->assignRole('admin');
        $refundUrl='/admin/nro-shop/orders/'.$order.'/refund';
        $body=['amount'=>90,'note'=>'Đã kiểm tra TXT: chỉ giao một món.'];
        $this->actingAs($admin,'web')->postJson($refundUrl,$body)->assertUnprocessable();
        $seller->givePermissionTo('item-orders.reconcile');
        $this->actingAs($seller,'web')->postJson($refundUrl,$body)->assertForbidden();
        $url='/app/nro-worker/jobs/'.$job['id']; $lease=['leaseToken'=>$job['leaseToken']];
        $this->withToken($this->token)->postJson($url.'/ready',[...$lease,'characterId'=>10,'name'=>'bot','mapId'=>5,'zone'=>7,'recipientName'=>'khach'])->assertOk();
        $this->postJson($url.'/begin-round',$lease)->assertOk();
        $lines=DB::table('item_order_items')->where('order_id',$order)->orderBy('id')->get()->values()->map(fn($i,$n)=>['id'=>$i->id,'delivered'=>$n===0?1:0])->all();
        $this->postJson($url.'/progress',[...$lease,'items'=>$lines])->assertOk();
        $this->postJson($url.'/complete',[...$lease,'outcome'=>'review'])->assertOk()->assertJsonPath('retrySafe',true);
        $this->actingAs($admin,'web')->postJson($refundUrl,['amount'=>201,'note'=>$body['note']])->assertUnprocessable();
        Event::fake([NroShopUpdated::class]);
        $this->postJson($refundUrl,$body)->assertOk();
        Event::assertDispatched(NroShopUpdated::class,fn($e)=>in_array($buyer->id,$e->buyerIds));
        $this->postJson($refundUrl,$body)->assertOk();
        $this->postJson($refundUrl,['amount'=>100,'note'=>$body['note']])->assertUnprocessable();
        $this->assertEquals(890,$buyer->fresh()->balance); $this->assertEquals(110,$seller->fresh()->balance);
        $this->assertDatabaseHas('item_orders',['id'=>$order,'status'=>'refunded','refund_amount'=>90,'refund_actor_id'=>$admin->id,'refund_note'=>$body['note']]);
        $this->assertEquals(0,DB::table('nro_inventory_items')->where('account_id',$a->id)->sum('reserved'));
        $this->assertEquals(1,DB::table('transactions')->where('idempotency_key',"nro-order:$order:admin-refund")->count());
        // A late, contradictory result is acknowledged and audited, never applied to a closed order.
        $late=[...$lease,'items'=>array_map(fn($l)=>['id'=>$l['id'],'delivered'=>1],$lines),'payload'=>$this->payload()];
        $this->withToken($this->token)->postJson($url.'/progress',$late)->assertOk()->assertJsonPath('alreadyFinalized',true);
        $this->postJson($url.'/progress',$late)->assertOk();
        $this->assertEquals(1,DB::table('nro_late_results')->where('job_id',$job['id'])->where('kind','progress')->count());
        $this->assertStringNotContainsString('NEVER_PUBLIC',DB::table('nro_late_results')->where('job_id',$job['id'])->where('kind','progress')->value('payload_json'));
        $this->assertEquals(1,DB::table('item_order_items')->where('order_id',$order)->sum('delivered'));
        $this->assertEquals(890,$buyer->fresh()->balance);
    }

    public function test_verified_progress_recovers_after_lease_expiry_without_double_delivery_or_credit(): void
    {
        [$seller,$a,$buyer,$order,$job]=$this->controlsFixture();
        $url='/app/nro-worker/jobs/'.$job['id']; $lease=['leaseToken'=>$job['leaseToken']];
        $this->postJson($url.'/ready',[...$lease,'characterId'=>10,'name'=>'bot','mapId'=>5,'zone'=>7,'recipientName'=>'khach'])->assertOk();
        $this->postJson($url.'/begin-round',$lease)->assertOk();
        $this->travel(4)->minutes();
        $this->postJson('/app/nro-worker/claim',['protocolVersion'=>3,'types'=>['delivery']])->assertOk();
        $this->assertDatabaseHas('item_orders',['id'=>$order,'status'=>'review']);
        $lines=DB::table('item_order_items')->where('order_id',$order)->get()->map(fn($i)=>['id'=>$i->id,'delivered'=>$i->quantity])->all();
        $progress=[...$lease,'items'=>$lines,'payload'=>$this->payload(0)];
        $this->postJson($url.'/progress',$progress)->assertOk();
        $this->postJson($url.'/progress',$progress)->assertOk();
        $this->assertEquals(0,DB::table('nro_inventory_items')->where('account_id',$a->id)->sum('reserved'));
        $complete=[...$lease,'outcome'=>'success','payload'=>$this->payload(0)];
        $this->postJson($url.'/complete',$complete)->assertOk();
        $this->postJson($url.'/complete',$complete)->assertOk();
        $this->assertEquals(200,$seller->fresh()->balance); $this->assertEquals(800,$buyer->fresh()->balance);
        $this->assertDatabaseHas('item_orders',['id'=>$order,'status'=>'completed']);
        $this->assertNull($a->fresh()->last_synced_at);
        $this->postJson($url.'/progress',['leaseToken'=>(string)Str::uuid(),'items'=>$lines])->assertForbidden();
        $this->travelBack();
    }

    public function test_snapshot_auto_retry_stops_after_three_jobs_and_manual_scan_resets_only_that_account(): void
    {
        $seller=$this->seller(); $a=$this->warehouse($seller); $a->update(['last_synced_at'=>null]);
        for($attempt=1;$attempt<=3;$attempt++) {
            $job=$this->withToken($this->token)->postJson('/app/nro-worker/claim',['protocolVersion'=>3,'types'=>['snapshot']])->assertOk()->json('data');
            $this->assertNotNull($job);
            $body=['leaseToken'=>$job['leaseToken'],'outcome'=>'failed','message'=>'Game không phản hồi.'];
            $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete',$body)->assertOk();
            $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete',$body)->assertOk();
            $this->assertEquals($attempt,$a->fresh()->snapshot_failures);
            $this->travel(3)->minutes();
        }
        $this->postJson('/app/nro-worker/claim',['protocolVersion'=>3,'types'=>['snapshot']])->assertOk()->assertJsonPath('data',null);
        $this->assertStringContainsString('3 lượt',$a->fresh()->publish_error);
        $other=$this->warehouse($this->seller()); $other->update(['last_synced_at'=>null]);
        $job=$this->postJson('/app/nro-worker/claim',['protocolVersion'=>3,'types'=>['snapshot']])->assertOk()->json('data');
        $this->assertEquals($other->id,$job['account']['id']);
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete',['leaseToken'=>$job['leaseToken'],'outcome'=>'success','payload'=>$this->payload()])->assertOk();
        $this->actingAs($seller,'web')->postJson('/admin/nro-shop/accounts/'.$a->id.'/scan')->assertOk();
        $this->assertEquals(0,$a->fresh()->snapshot_failures);
        $job=$this->withToken($this->token)->postJson('/app/nro-worker/claim',['protocolVersion'=>3,'types'=>['snapshot']])->assertOk()->json('data');
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete',['leaseToken'=>$job['leaseToken'],'outcome'=>'success','payload'=>$this->payload()])->assertOk();
        $this->assertEquals(0,$a->fresh()->snapshot_failures); $this->assertNull($a->fresh()->publish_error);
        $this->travelBack();
    }

    public function test_incomplete_snapshot_and_expired_snapshot_each_count_one_failed_attempt(): void
    {
        $a=$this->warehouse($this->seller()); $a->update(['last_synced_at'=>null]);
        $job=$this->withToken($this->token)->postJson('/app/nro-worker/claim',['protocolVersion'=>3,'types'=>['snapshot']])->assertOk()->json('data');
        $this->postJson('/app/nro-worker/jobs/'.$job['id'].'/complete',['leaseToken'=>$job['leaseToken'],'outcome'=>'success','payload'=>$this->payload(2,false)])->assertOk();
        $this->assertEquals(1,$a->fresh()->snapshot_failures);
        $this->travel(3)->minutes();
        $job=$this->postJson('/app/nro-worker/claim',['protocolVersion'=>3,'types'=>['snapshot']])->assertOk()->json('data');
        $this->travel(4)->minutes();
        $this->postJson('/app/nro-worker/claim',['protocolVersion'=>3,'types'=>['snapshot']])->assertOk();
        $this->postJson('/app/nro-worker/claim',['protocolVersion'=>3,'types'=>['snapshot']])->assertOk();
        $this->assertEquals(2,$a->fresh()->snapshot_failures);
        $this->travelBack();
    }

}
