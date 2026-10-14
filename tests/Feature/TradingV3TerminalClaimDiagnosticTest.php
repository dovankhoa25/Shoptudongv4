<?php

namespace Tests\Feature;

use App\Models\{GemBot, GemTransaction, InventoryMovement, Server, User};
use App\Services\TradingV3\GemTradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Cached claims and obsolete phases acknowledge terminal state without reopening work. */
class TradingV3TerminalClaimDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_claim_and_obsolete_phase_are_read_only_acknowledgements(): void
    {
        Event::fake();
        $worker = '00000000-0000-4000-8000-000000000091';
        $token = '00000000-0000-4000-8000-000000000092';
        $server = Server::create(['name' => 'synthetic-server', 'status' => true]);
        $bot = GemBot::create([
            'name' => 'synthetic-bot', 'account_name' => 'synthetic', 'account_password' => 'synthetic',
            'server_id' => $server->id, 'gem_qty' => 90, 'gift_ticket_qty' => 9, 'consign_item_qty' => 0,
            'status' => true, 'map_name' => 'Synthetic', 'map_id' => '84', 'area_number' => '41', 'coordinates' => '652-336',
        ]);
        $bot->forceFill(['v3_worker_id' => $worker, 'v3_lease_until' => now()->addMinutes(5)])->save();
        $order = GemTransaction::create([
            'user_id' => User::factory()->create()->id, 'server_id' => $server->id, 'bot_id' => $bot->id,
            'character_name' => 'synthetic-buyer', 'character_id' => 1234, 'gem_qty' => 10,
            'amount_vnd' => 10000, 'price_at_transaction' => 10, 'delivery_method' => 'gift_ticket',
            'status' => 'completed', 'updated_by' => 'app', 'processing_version' => 3,
            'processing_owner' => $worker, 'processing_token' => $token,
            'delivery_phase' => 'completed', 'bot_gem_spent_qty' => 10, 'delivered_gem_qty' => 10,
            'tickets_used_qty' => 1, 'completed_at' => now(),
            'delivery_data' => ['claim' => ['character_name' => 'synthetic-buyer', 'gem_qty' => 10, 'delivery_method' => 'gift_ticket']],
        ]);
        $beforeBot = $bot->fresh()->getAttributes();
        $beforeOrder = $order->fresh()->getAttributes();
        $service = app(GemTradingService::class);
        $response = $service->updateOrder($order->id, ['worker_id' => $worker, 'bot_id' => $bot->id, 'action' => 'claim']);
        $this->assertSame('completed', $response->status);
        $this->assertSame($token, $response->processing_token);

        $phase = $service->updateOrder($order->id, [
                'worker_id' => $worker, 'bot_id' => $bot->id, 'action' => 'phase', 'processing_token' => $token,
                'step_id' => '00000000-0000-4000-8000-000000000093', 'phase' => 'waiting_customer', 'character_id' => 1234,
            ]);
        $this->assertSame('completed', $phase->status);
        $this->assertSame($beforeBot, $bot->fresh()->getAttributes());
        $this->assertSame($beforeOrder, $order->fresh()->getAttributes());
        $this->assertSame(0, InventoryMovement::count());
    }
}
