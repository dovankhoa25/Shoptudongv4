<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\DashboardController;
use App\Models\Bot;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_aggregates_preserve_counts_totals_and_historical_stock(): void
    {
        $server = Server::create(['name' => 'Server', 'status' => true]);
        $bot = Bot::withoutEvents(fn () => Bot::create([
            'name' => 'Bot', 'account_name' => 'test', 'account_password' => 'test',
            'type' => 'selling_main', 'server_id' => $server->id, 'server_game_id' => 1,
            'gold_qty' => 10000, 'gold_bar_qty' => 0, 'status' => true,
            'map_id' => '1', 'area_number' => '0', 'map_name' => 'Kame',
        ]));
        foreach ([['order', 1000], ['order', 1500], ['import', 400]] as [$type, $gold]) {
            DB::table('gold_transactions')->insert([
                'type' => $type, 'user_id' => 1, 'server_id' => $server->id,
                'character_name' => 'test', 'price_at_transaction' => 1,
                'gold_qty' => $gold, 'status' => 'completed', 'updated_at' => '2026-09-14 12:00:00',
            ]);
        }
        foreach ([5, 7] as $gems) {
            DB::table('gem_transactions')->insert([
                'user_id' => 1, 'server_id' => $server->id, 'character_name' => 'test',
                'price_at_transaction' => 1, 'gem_qty' => $gems, 'status' => 'completed',
                'updated_at' => '2026-09-14 12:00:00',
            ]);
        }
        foreach ([['sale', -2500, '2026-09-14 00:00:00'], ['import', 400, '2026-09-14 12:00:00'],
            ['adjustment', 50, '2026-09-14 13:00:00'], ['adjustment', 10, '2026-09-15 12:00:00'],
            ['adjustment', 20, '2026-09-16 12:00:00']] as [$type, $delta, $time]) {
            DB::table('inventory_movements')->insert([
                'server_id' => $server->id, 'bot_id' => $bot->id, 'bot_type' => 'gold',
                'asset_type' => 'pure_gold', 'movement_type' => $type, 'quantity_delta' => $delta,
                'balance_before' => 0, 'balance_after' => 0, 'source' => 'test', 'occurred_at' => $time,
            ]);
        }
        $request = Request::create('/admin/dashboard', 'GET', ['date' => '2026-09-14']);
        $request->headers->set('X-Inertia', 'true');
        $data = app(DashboardController::class)->index($request)->toResponse($request)->getData(true)['props']['servers'][0];

        $gold = $data['reconciliation']['gold'];
        $this->assertSame(2, $gold['order_count']);
        $this->assertSame(1, $gold['import_count']);
        $this->assertSame(2500, $gold['sold_converted']);
        $this->assertSame(400, $gold['imported_converted']);
        $this->assertSame(9970, $gold['actual_converted']);
        $this->assertSame(12020, $gold['opening_converted']);
        $this->assertSame(0, $gold['difference']);
        $this->assertCount(1, $gold['adjustment_details']);
        $this->assertSame(2, $data['reconciliation']['gem']['order_count']);
        $this->assertSame(12, $data['reconciliation']['gem']['sold']);
    }
}
