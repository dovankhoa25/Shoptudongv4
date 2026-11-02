<?php

namespace Tests\Feature;

use App\Models\{ChatConversation, ChatMessage, User};
use App\Services\AdminLive\Reader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class AdminChatProjectionPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32)), 'access_security.admin_approval_required' => false]);
    }

    private function agent(bool $all = true): User
    {
        $agent = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        foreach (['chats.view', 'chats.reply', ...($all ? ['chats.view_all'] : [])] as $name) {
            $agent->givePermissionTo(Permission::findOrCreate($name, 'web'));
        }
        return $agent;
    }

    private function conversation(User $customer, ?User $assignee = null, string $status = 'waiting_agent', int $age = 0): ChatConversation
    {
        $at = now()->subDays($age);
        $conversation = ChatConversation::create([
            'customer_id' => $customer->id, 'assigned_to_id' => $assignee?->id,
            'category' => 'general', 'status' => $status, 'priority' => 'normal',
            'last_message_at' => $at, 'resolved_at' => in_array($status, ['resolved', 'closed']) ? $at : null,
        ]);
        $message = ChatMessage::create([
            'conversation_id' => $conversation->id, 'sender_id' => $customer->id,
            'sender_kind' => 'customer', 'body' => 'Message '.$conversation->id, 'client_message_id' => (string) Str::uuid(),
        ]);
        $conversation->update(['last_message_id' => $message->id]);
        return $conversation;
    }

    private function projection(User $agent, int $pages, array $filters = []): array
    {
        return app(Reader::class)->read($agent, '/admin/chat/conversations?'.http_build_query([
            'view' => 'active', 'per_page' => 2, ...$filters, 'live_pages' => $pages,
        ]));
    }

    private function ordinaryPages(User $agent, int $pages, array $filters = []): array
    {
        $combined = [];
        for ($page = 1; $page <= $pages; $page++) {
            $result = app(Reader::class)->read($agent, '/admin/chat/conversations?'.http_build_query([
                'view' => 'active', 'per_page' => 2, ...$filters, 'page' => $page,
            ]));
            $combined = [...$combined, ...$result['data']];
            if ($page >= $result['meta']['last_page']) break;
        }
        return ['data' => $combined, ...array_intersect_key($result, array_flip(['counts', 'unread_counts', 'unread_total']))];
    }

    public function test_three_loaded_pages_share_one_query_pass_and_match_ordinary_page_order_and_counts(): void
    {
        $this->freezeTime();
        $agent = $this->agent(); $customer = User::factory()->create();
        for ($i = 0; $i < 12; $i++) $this->conversation($customer, null, $i % 2 ? 'waiting_customer' : 'waiting_agent');
        $this->projection($agent, 1); // Warm only authorization metadata, not chat rows.

        DB::enableQueryLog(); DB::flushQueryLog();
        $one = $this->projection($agent, 1); $oneQueries = DB::getQueryLog();
        DB::flushQueryLog();
        $three = $this->projection($agent, 3); $threeQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(2, $one['data']); $this->assertCount(6, $three['data']);
        $this->assertSame(count($oneQueries), count($threeQueries), 'Loaded pages must not multiply controller/aggregate queries.');
        $this->assertSame(1, collect($threeQueries)->filter(fn ($query) => str_contains(strtolower($query['query']), 'order by case chat_conversations.status'))->count());
        $expected = $this->ordinaryPages($agent, 3);
        foreach ($expected as $key => $value) $this->assertSame($value, $three[$key]);
        $this->assertSame(3, $three['meta']['current_page']);
        $this->assertSame(6, $three['meta']['last_page']);
        $this->assertSame(2, $three['meta']['per_page']);
        $this->assertSame(1, $three['meta']['from']); $this->assertSame(6, $three['meta']['to']);
    }

    public function test_new_messages_and_deletions_rebuild_the_same_window_and_clamp_its_loaded_page(): void
    {
        $this->freezeTime(); $agent = $this->agent(); $customer = User::factory()->create();
        $conversations = collect(range(1, 7))->map(fn () => $this->conversation($customer));
        $before = $this->projection($agent, 3);
        $this->assertSame(3, $before['meta']['current_page']);
        $this->assertSame(4, $before['meta']['last_page']);

        $this->travel(1)->minutes();
        $target = $conversations->first();
        $message = ChatMessage::create(['conversation_id' => $target->id, 'sender_id' => $customer->id,
            'sender_kind' => 'customer', 'body' => 'Newest message', 'client_message_id' => (string) Str::uuid()]);
        $target->update(['last_message_id' => $message->id, 'last_message_at' => now()]);
        foreach ($conversations->slice(1, 3) as $conversation) $conversation->delete();

        $after = $this->projection($agent, 3);
        foreach ($this->ordinaryPages($agent, 3) as $key => $value) $this->assertSame($value, $after[$key]);
        $this->assertSame($target->id, $after['data'][0]['id']);
        $this->assertSame('Newest message', $after['data'][0]['last_message']['body']);
        $this->assertCount(4, $after['data']);
        $this->assertSame(2, $after['meta']['current_page']); $this->assertSame(2, $after['meta']['last_page']);
        $this->assertSame(4, $after['meta']['total']);
    }

    public function test_loaded_projection_keeps_search_assignment_period_status_and_staff_visibility(): void
    {
        $this->freezeTime(); $agent = $this->agent(false); $otherAgent = $this->agent(false);
        $customer = User::factory()->create(['username' => 'matching-customer']);
        $otherCustomer = User::factory()->create(['username' => 'different-customer']);
        $visible = collect(range(1, 5))->map(fn () => $this->conversation($customer, $agent, 'resolved'));
        $this->conversation($customer, $otherAgent, 'resolved');
        $this->conversation($otherCustomer, $agent, 'resolved');
        $this->conversation($customer, $agent, 'resolved', 10);
        $this->conversation($customer, $agent, 'closed');
        $filters = ['view' => 'completed', 'status' => 'resolved', 'period' => '7d', 'assignment' => 'mine', 'search' => 'matching'];
        $actual = $this->projection($agent, 3, $filters);
        foreach ($this->ordinaryPages($agent, 3, $filters) as $key => $value) $this->assertSame($value, $actual[$key]);
        $this->assertEqualsCanonicalizing($visible->pluck('id')->all(), array_column($actual['data'], 'id'));
        $this->assertSame(5, $actual['meta']['total']);
        $this->assertTrue(collect($actual['data'])->every(fn ($row) => $row['permissions']['reply'] === true));

        $agent->revokePermissionTo('chats.view');
        try {
            $this->projection($agent, 3, $filters);
            $this->fail('Losing chat-view permission must stop live projections.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_http_parameters_cannot_enable_a_loaded_window_and_normal_pagination_is_unchanged(): void
    {
        $agent = $this->agent(); $customer = User::factory()->create();
        for ($i = 0; $i < 7; $i++) $this->conversation($customer);
        $this->actingAs($agent, 'web')->getJson('/admin/chat/conversations?view=active&per_page=2&page=2&live_pages=100&admin_live_chat_loaded_rows=5000')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 4)->assertJsonPath('meta.per_page', 2);
    }

    public function test_projection_load_limit_keeps_the_existing_hundred_page_cap(): void
    {
        $agent = $this->agent(); $customer = User::factory()->create();
        for ($i = 0; $i < 105; $i++) $this->conversation($customer);
        $actual = $this->projection($agent, 1000, ['per_page' => 1]);
        $this->assertCount(100, $actual['data']);
        $this->assertSame(100, $actual['meta']['current_page']);
        $this->assertSame(105, $actual['meta']['last_page']);
        $this->assertSame(1, $actual['meta']['per_page']);
    }
}
