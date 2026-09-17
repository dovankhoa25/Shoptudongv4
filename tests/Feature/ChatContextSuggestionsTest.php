<?php
namespace Tests\Feature;

use App\Models\{User, NickOrder, GoldTransaction, GemTransaction, Server};
use App\Services\Chat\ChatSubjectResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChatContextSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggestions_filter_nick_age_and_open_gold_gem_orders_without_changing_ownership_resolution(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $server = Server::create(['name' => 'Test server', 'status' => true]);
        $recent = NickOrder::create(['nick_id' => 123, 'buyer_id' => $user->id, 'seller_id' => $other->id, 'price' => 1000, 'status' => 'completed']);
        $old = NickOrder::create(['nick_id' => 124, 'buyer_id' => $user->id, 'seller_id' => $other->id, 'price' => 1000, 'status' => 'completed']);
        DB::table('nick_orders')->where('id', $old->id)->update(['created_at' => now()->subHours(25)]);
        NickOrder::create(['nick_id' => 125, 'buyer_id' => $other->id, 'seller_id' => $user->id, 'price' => 1000, 'status' => 'completed']);
        foreach (['pending', 'processing', 'completed', 'cancelled'] as $status) {
            GoldTransaction::create(['type' => 'order', 'user_id' => $user->id, 'server_id' => $server->id, 'character_name' => 'test', 'price_at_transaction' => 1000, 'status' => $status]);
            GemTransaction::create(['user_id' => $user->id, 'server_id' => $server->id, 'character_name' => 'test', 'price_at_transaction' => 1000, 'status' => $status]);
        }
        $resolver = app(ChatSubjectResolver::class);
        $items = $resolver->listFor($user);
        $this->assertCount(5, $items);
        $this->assertSame([$recent->id], $items->where('type', 'nick_order')->pluck('id')->all());
        $this->assertEqualsCanonicalizing(['pending', 'processing'], $items->where('type', 'gold_transaction')->pluck('status')->all());
        $this->assertEqualsCanonicalizing(['pending', 'processing'], $items->where('type', 'gem_transaction')->pluck('status')->all());
        $this->assertSame($old->id, $resolver->resolveOwned('nick_order', $old->id, $user)->id);
    }
}
