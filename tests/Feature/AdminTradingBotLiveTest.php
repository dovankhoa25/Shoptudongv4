<?php

namespace Tests\Feature;

use App\Enums\Permission as AdminPermission;
use App\Events\{AdminEvent, AdminViewPatched};
use App\Models\{Bot, GemBot, GemPrice, GemTransaction, GoldTransaction, Server, User};
use App\Services\AdminLive\{Reader, Updates, Views};
use App\Services\TradingV3\{GemTradingService, GoldTradingService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Auth, Cache, DB, Event, Http};
use Illuminate\Support\Str;
use Spatie\Permission\Models\{Permission, Role};
use Tests\TestCase;

class AdminTradingBotLiveTest extends TestCase
{
    use RefreshDatabase;

    private string $worker = '00000000-0000-4000-8000-000000000901';
    private Server $server;
    private int $loginId;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
        config()->set('trading_v3', require base_path('config/trading_v3.php'));
        config()->set('trading_v3.enabled', true);
        $this->server = Server::create(['name' => 'Admin trading test', 'status' => true]);
        $this->loginId = (int) DB::table('server_game_login')->max('id') + 1;
        DB::table('server_game_login')->insert(['id' => $this->loginId, 'name' => 'Test login', 'ip' => 'test.invalid', 'port' => '14445']);
    }

    public function actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);
        $this->withSession([Auth::guard('web')->getName() => $user->getAuthIdentifier()]);
        $this->withCredentials()->withCookie(config('session.cookie'), app('session')->getId());
        return $this;
    }

    private function admin(): User
    {
        $role = Role::findOrCreate('super-admin', 'web');
        foreach (AdminPermission::cases() as $permission) $role->givePermissionTo(Permission::findOrCreate($permission->value, 'web'));
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole($role);
        $this->actingAs($user);
        return $user;
    }

    private function bot(bool $gem, array $attributes = []): Bot|GemBot
    {
        $common = ['name' => 'Live stock target', 'account_name' => 'account-'.Str::uuid(), 'account_password' => 'never-broadcast-bot-password',
            'server_id' => $this->server->id, 'server_game_id' => $this->loginId, 'map_name' => 'Siêu thị', 'map_id' => '84',
            'area_number' => '41', 'coordinates' => '652-336', 'proxy' => '127.0.0.1:8080:test-proxy-user:test-proxy-password', 'status' => true];
        return $gem ? GemBot::create([...$common, 'gem_qty' => 100, 'gift_ticket_qty' => 10, 'consign_item_qty' => 3, ...$attributes])
            : Bot::create([...$common, 'type' => 'selling_main', 'gold_qty' => 0, 'gold_bar_qty' => 10, ...$attributes]);
    }

    private function subscribe(string $url): string
    {
        $id = $this->postJson('/admin/live-views', ['url' => $url])->assertOk()->json('id');
        $this->postJson('/admin/live-views/'.$id.'/sync')->assertOk();
        app(Updates::class)->flush();
        return $id;
    }

    private function state(string $id): array
    {
        return Cache::get('admin-live:state:'.$id)['data'];
    }

    public function test_bot_projections_preserve_filters_pagination_stats_and_exclude_passwords(): void
    {
        $admin = $this->admin();
        foreach ([false, true] as $gem) {
            $target = $this->bot($gem, ['name' => 'Matching live bot']);
            $this->bot($gem, ['name' => 'Excluded live bot', 'status' => false]);
            $path = $gem ? '/admin/gem-bots' : '/admin/bots';
            $key = $gem ? 'gemBots' : 'bots';
            $data = app(Reader::class)->read($admin, $path.'?search=name:Matching&status=1&page=1');
            $this->assertSame([$key, 'stats'], array_keys($data));
            $this->assertCount(1, $data[$key]['data']);
            $this->assertSame($target->id, $data[$key]['data'][0]['id']);
            $this->assertSame(1, $data[$key]['meta']['current_page']);
            $this->assertSame(2, $data['stats']['total_bots']);
            $this->assertStringNotContainsString('never-broadcast-bot-password', json_encode($data));
            $this->assertArrayNotHasKey('account_password', $data[$key]['data'][0]);
            // This is the existing private, authorized configuration projection.
            $this->assertSame($target->proxy, $data[$key]['data'][0]['map_info']['proxy']);
            $detail = app(Reader::class)->read($admin, $path.'/'.$target->id);
            $this->assertSame($target->id, $detail[$gem ? 'gemBot' : 'bot']['data']['id']);
            $this->assertStringNotContainsString('never-broadcast-bot-password', json_encode($detail));
        }
    }

    public function test_bot_and_price_live_views_keep_existing_permissions_and_exclude_edit_forms(): void
    {
        $this->actingAs(User::factory()->create(['status' => User::STATUS_ACTIVE]));
        foreach (['/admin/bots', '/admin/gem-bots', '/admin/gem-prices'] as $path) {
            $this->postJson('/admin/live-views', ['url' => $path])->assertForbidden();
        }
        foreach (['/admin/gem-bots/create', '/admin/gem-bots/1/edit', '/admin/gem-prices/api/current-prices', '/admin/bots/1/toggle-status'] as $path) {
            $this->assertNull(Views::resolve($path));
            $this->postJson('/admin/live-views', ['url' => $path])->assertUnprocessable();
        }
        $this->assertDatabaseCount('admin_live_views', 0);
    }

    public function test_lease_release_and_unchanged_stock_snapshot_do_not_broadcast_bot_updates(): void
    {
        $gold = $this->bot(false);
        $gem = $this->bot(true);
        Event::fake([AdminEvent::class]);
        foreach ([$gold, $gem] as $bot) {
            $service = app($bot instanceof GemBot ? GemTradingService::class : GoldTradingService::class);
            $service->updateBot($bot->id, ['worker_id' => $this->worker, 'action' => 'lease']);
            $this->travel(15)->seconds();
            $service->updateBot($bot->id, ['worker_id' => $this->worker, 'action' => 'lease']);
            $snapshot = $bot instanceof GemBot ? ['gem_qty' => 100, 'gift_ticket_qty' => 10, 'consign_item_qty' => 3]
                : ['gold_qty' => 0, 'gold_bar_qty' => 10];
            $service->updateBot($bot->id, ['worker_id' => $this->worker, 'action' => 'stock', 'stock_version' => 1, ...$snapshot]);
            $service->updateBot($bot->id, ['worker_id' => $this->worker, 'action' => 'release']);
        }
        Event::assertNotDispatched(AdminEvent::class);
    }

    public function test_confirmed_stock_sync_updates_private_bot_views_without_changing_web_targets(): void
    {
        $this->admin();
        $gold = $this->bot(false);
        $gem = $this->bot(true);
        $views = [$this->subscribe('/admin/bots'), $this->subscribe('/admin/gem-bots')];
        Event::fake([AdminViewPatched::class]);
        app(GoldTradingService::class)->updateBot($gold->id, ['worker_id' => $this->worker, 'action' => 'lease']);
        app(GemTradingService::class)->updateBot($gem->id, ['worker_id' => $this->worker, 'action' => 'lease']);
        app(GoldTradingService::class)->updateBot($gold->id, ['worker_id' => $this->worker, 'action' => 'stock', 'stock_version' => 1, 'gold_qty' => 0, 'gold_bar_qty' => 8]);
        app(GemTradingService::class)->updateBot($gem->id, ['worker_id' => $this->worker, 'action' => 'stock', 'stock_version' => 1, 'gem_qty' => 90, 'gift_ticket_qty' => 9, 'consign_item_qty' => 2]);
        app(Updates::class)->flush();
        $this->assertSame(8, $this->state($views[0])['bots']['data'][0]['gold_bar_qty']);
        $this->assertSame(90, $this->state($views[1])['gemBots']['data'][0]['gem_qty']);
        $this->assertSame(9, $this->state($views[1])['gemBots']['data'][0]['gift_ticket_qty']);
        $this->assertSame(2, $this->state($views[1])['gemBots']['data'][0]['consign_item_qty']);
        Event::assertDispatchedTimes(AdminViewPatched::class, 2);
        Event::assertDispatched(AdminViewPatched::class, fn ($event) => str_starts_with($event->broadcastOn()[0]->name, 'private-Admin.View.'));
        foreach ([$gold, $gem] as $bot) {
            $bot->refresh();
            $this->assertTrue($bot->status);
            $this->assertSame('84', $bot->map_id);
            $this->assertSame('41', $bot->area_number);
            $this->assertSame('652-336', $bot->coordinates);
        }
    }

    public function test_completed_receipts_update_bot_and_order_views_and_repeat_is_silent(): void
    {
        $this->admin();
        foreach ([false, true] as $gem) {
            $bot = $this->bot($gem);
            $service = app($gem ? GemTradingService::class : GoldTradingService::class);
            $service->updateBot($bot->id, ['worker_id' => $this->worker, 'action' => 'lease']);
            $common = ['user_id' => User::factory()->create()->id, 'server_id' => $this->server->id, 'character_name' => 'lezit1',
                'amount_vnd' => 10000, 'price_at_transaction' => 20, 'status' => 'pending', 'updated_by' => 'web'];
            $order = $gem ? GemTransaction::create([...$common, 'gem_qty' => 20, 'delivery_method' => 'gift_ticket'])
                : GoldTransaction::create([...$common, 'type' => 'order', 'gold_qty' => 74000000, 'gold_bar_qty' => 2, 'pure_gold_qty' => 0]);
            $token = $service->updateOrder($order->id, ['worker_id' => $this->worker, 'bot_id' => $bot->id, 'action' => 'claim'])->processing_token;
            $service->updateOrder($order->id, ['worker_id' => $this->worker, 'bot_id' => $bot->id, 'action' => 'phase',
                'processing_token' => $token, 'character_id' => 1234, 'step_id' => (string) Str::uuid(), 'phase' => $gem ? 'gifting' : 'trading']);
            $botView = $this->subscribe($gem ? '/admin/gem-bots' : '/admin/bots');
            $orderView = $this->subscribe(($gem ? '/admin/gem-orders/' : '/admin/orders/').$order->id);
            Event::fake([AdminViewPatched::class]);
            $receipt = ['worker_id' => $this->worker, 'bot_id' => $bot->id, 'action' => 'receipt', 'processing_token' => $token,
                'character_id' => 1234, 'step_id' => (string) Str::uuid(), 'completed' => true];
            $receipt += $gem ? ['gem_spent_qty' => 20, 'delivered_gem_qty' => 20, 'tickets_used_qty' => 2,
                'bot_snapshot' => ['stock_version' => 1, 'gem_qty' => 80, 'gift_ticket_qty' => 8, 'consign_item_qty' => 3], 'evidence' => ['kind' => 'gift', 'confirmed' => true]]
                : ['bars_qty' => 2, 'pure_gold_qty' => 0, 'bot_snapshot' => ['stock_version' => 1, 'gold_qty' => 0, 'gold_bar_qty' => 8], 'evidence' => ['kind' => 'sale', 'confirmed' => true]];
            $service->updateOrder($order->id, $receipt);
            app(Updates::class)->flush();
            $this->assertSame(8, $this->state($botView)[$gem ? 'gemBots' : 'bots']['data'][0][$gem ? 'gift_ticket_qty' : 'gold_bar_qty']);
            $this->assertSame('completed', $this->state($orderView)['order']['data']['status']);
            $count = Event::dispatched(AdminViewPatched::class)->count();
            $this->assertGreaterThanOrEqual(2, $count);
            $service->updateOrder($order->id, $receipt);
            app(Updates::class)->flush();
            $this->assertSame($count, Event::dispatched(AdminViewPatched::class)->count());
        }
    }

    public function test_bot_changes_publish_only_after_commit_and_rollback_publishes_nothing(): void
    {
        $bot = $this->bot(true);
        Event::fake([AdminEvent::class]);
        DB::beginTransaction();
        $bot->update(['gem_qty' => 90]);
        Event::assertNotDispatched(AdminEvent::class);
        DB::rollBack();
        Event::assertNotDispatched(AdminEvent::class);
        DB::beginTransaction();
        $bot->refresh()->update(['gem_qty' => 80]);
        Event::assertNotDispatched(AdminEvent::class);
        DB::commit();
        Event::assertDispatchedTimes(AdminEvent::class, 1);
        Event::assertDispatched(AdminEvent::class, function ($event) use ($bot) {
            $this->assertSame('gem_bot', $event->resource);
            $this->assertSame($bot->id, $event->resourceId);
            $this->assertSame('private-Admin.realtime', $event->broadcastOn()->name);
            $payload = json_encode($event->broadcastWith());
            $this->assertStringNotContainsString('never-broadcast-bot-password', $payload);
            $this->assertStringNotContainsString('test-proxy-password', $payload);
            $this->assertStringNotContainsString($bot->account_name, $payload);
            return true;
        });
    }

    public function test_revoked_bot_permission_stops_bot_publication(): void
    {
        $admin = $this->admin();
        $bot = $this->bot(true);
        $id = $this->subscribe('/admin/gem-bots');
        $admin->removeRole('super-admin');
        Event::fake([AdminViewPatched::class]);
        $bot->update(['gem_qty' => 90]);
        app(Updates::class)->flush();
        Event::assertDispatched(AdminViewPatched::class, fn ($event) => ($event->frame['revoked'] ?? false) === true);
        $this->assertDatabaseMissing('admin_live_views', ['id' => $id]);
    }

    public function test_disabled_bot_notice_uses_web_status_and_contains_no_connection_credentials(): void
    {
        foreach ([false, true] as $gem) {
            $bot = $this->bot($gem);
            Event::fake([AdminEvent::class]);
            $bot->update(['status' => false]);
            Event::assertDispatchedTimes(AdminEvent::class, 1);
            Event::assertDispatched(AdminEvent::class, function ($event) use ($gem, $bot) {
                $this->assertSame($gem ? 'gem_bot' : 'gold_bot', $event->resource);
                $this->assertSame('0', $event->status);
                $this->assertSame('status_updated', $event->action);
                $this->assertStringContainsString('tắt', $event->message);
                $payload = json_encode($event->broadcastWith());
                $this->assertStringNotContainsString($bot->account_password, $payload);
                $this->assertStringNotContainsString($bot->proxy, $payload);
                return true;
            });
        }
    }

    public function test_gem_price_changes_refresh_filtered_private_price_view(): void
    {
        $this->admin();
        $price = GemPrice::create(['server_id' => $this->server->id, 'multiplier' => 20, 'gift_ticket_multiplier' => null, 'min_amount' => 10000, 'status' => true]);
        $id = $this->subscribe('/admin/gem-prices?server_id='.$this->server->id.'&status=1');
        Event::fake([AdminViewPatched::class]);
        DB::transaction(fn () => $price->update(['gift_ticket_multiplier' => 12.35]));
        app(Updates::class)->flush();
        $this->assertSame(12.35, (float) $this->state($id)['gemPrices']['data'][0]['gift_ticket_multiplier']);
        $this->assertSame(['gemPrices', 'servers', 'stats'], array_keys($this->state($id)));
        Event::assertDispatchedTimes(AdminViewPatched::class, 1);
    }

    public function test_ticket_reservations_refresh_unreserved_capacity_in_bot_list_and_detail(): void
    {
        $this->admin();
        $bot = $this->bot(true);
        $list = $this->subscribe('/admin/gem-bots');
        $detail = $this->subscribe('/admin/gem-bots/'.$bot->id);
        Event::fake([AdminViewPatched::class]);
        $capacity = function (int $expected) use ($list, $detail, $bot) {
            $this->assertSame($expected, $this->state($list)['gemBots']['data'][0]['gift_unreserved_gems']);
            $this->assertSame($expected, $this->state($detail)['gemBot']['data']['gift_unreserved_gems']);
            $this->assertSame(100, $bot->refresh()->gem_qty);
            $this->assertSame(10, $bot->gift_ticket_qty);
        };
        $capacity(100);
        $order = GemTransaction::create(['user_id' => User::factory()->create()->id, 'server_id' => $this->server->id,
            'bot_id' => $bot->id, 'character_name' => 'lezit1', 'gem_qty' => 20, 'amount_vnd' => 10000,
            'price_at_transaction' => 20, 'delivery_method' => 'gift_ticket', 'status' => 'pending', 'updated_by' => 'web']);
        app(Updates::class)->flush();
        $capacity(80);
        Event::assertDispatchedTimes(AdminViewPatched::class, 2);
        $order->update(['gem_qty' => 30]);
        app(Updates::class)->flush();
        $capacity(70);
        Event::assertDispatchedTimes(AdminViewPatched::class, 4);
        $order->update(['status' => 'cancelled']);
        app(Updates::class)->flush();
        $capacity(100);
        Event::assertDispatchedTimes(AdminViewPatched::class, 6);
        app(GemTradingService::class)->updateBot($bot->id, ['worker_id' => $this->worker, 'action' => 'lease']);
        $this->travel(15)->seconds();
        app(GemTradingService::class)->updateBot($bot->id, ['worker_id' => $this->worker, 'action' => 'lease']);
        app(Updates::class)->flush();
        $capacity(100);
        Event::assertDispatchedTimes(AdminViewPatched::class, 6);
    }

    public function test_bulk_bot_status_and_delete_refresh_live_views_and_noop_is_silent(): void
    {
        $this->admin();
        $bot = $this->bot(false, ['gold_bar_qty' => 0]);
        $id = $this->subscribe('/admin/bots');
        Event::fake([AdminViewPatched::class]);
        $this->post('/admin/bots/bulk-activate', ['bot_ids' => [$bot->id]])->assertRedirect();
        app(Updates::class)->flush();
        Event::assertNotDispatched(AdminViewPatched::class);
        $this->post('/admin/bots/bulk-deactivate', ['bot_ids' => [$bot->id]])->assertRedirect();
        app(Updates::class)->flush();
        $this->assertFalse($this->state($id)['bots']['data'][0]['status']);
        $this->assertSame(0, $this->state($id)['stats']['active_bots']);
        Event::assertDispatchedTimes(AdminViewPatched::class, 1);
        $this->post('/admin/bots/bulk-activate', ['bot_ids' => [$bot->id]])->assertRedirect();
        app(Updates::class)->flush();
        $this->assertTrue($this->state($id)['bots']['data'][0]['status']);
        $this->assertSame(1, $this->state($id)['stats']['active_bots']);
        Event::assertDispatchedTimes(AdminViewPatched::class, 2);
        $this->post('/admin/bots/bulk-delete', ['bot_ids' => [$bot->id]])->assertRedirect();
        app(Updates::class)->flush();
        $this->assertSame([], $this->state($id)['bots']['data']);
        $this->assertSame(0, $this->state($id)['stats']['total_bots']);
        Event::assertDispatchedTimes(AdminViewPatched::class, 3);
    }

    public function test_bulk_bot_status_rollback_cannot_publish_uncommitted_rows(): void
    {
        $this->admin();
        $bot = $this->bot(false);
        $id = $this->subscribe('/admin/bots');
        Event::fake([AdminViewPatched::class]);
        DB::beginTransaction();
        $this->post('/admin/bots/bulk-deactivate', ['bot_ids' => [$bot->id]])->assertRedirect();
        app(Updates::class)->flush();
        Event::assertNotDispatched(AdminViewPatched::class);
        DB::rollBack();
        app(Updates::class)->flush();
        Event::assertNotDispatched(AdminViewPatched::class);
        $this->assertTrue($bot->refresh()->status);
        $this->assertTrue($this->state($id)['bots']['data'][0]['status']);
    }
}
