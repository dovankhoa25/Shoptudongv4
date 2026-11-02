<?php

namespace Tests\Feature;

use App\Events\AdminViewPatched;
use App\Models\User;
use App\Services\AdminLive\{Reader, Updates};
use App\Services\Chat\ChatRealtimeChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Event};
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminLiveUpdateOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([AdminViewPatched::class]);
    }

    private function registerView(User $user, array $resources, string $credential, string $url = '/admin/chat/conversations'): string
    {
        $id = (string) Str::uuid();
        DB::table('admin_live_views')->insert([
            'id' => $id, 'user_id' => $user->id, 'credential_hash' => $credential,
            'url' => $url, 'mode' => 'page', 'resources' => json_encode($resources),
            'expires_at' => now()->addMinutes(30), 'revision' => 0,
        ]);
        return $id;
    }

    private function change(Updates $updates, string $resource = 'chat'): void
    {
        $updates->changed($resource);
        $updates->flush();
    }

    public function test_database_selection_returns_only_views_matching_the_changed_resources(): void
    {
        $user = User::factory()->create(); $credential = str_repeat('a', 64);
        $matching = [$this->registerView($user, ['chat'], $credential), $this->registerView($user, ['gold_order', 'chat'], $credential)];
        foreach (range(1, 30) as $number) $this->registerView($user, ['irrelevant-'.$number], $credential);
        $this->registerView($user, ['chatting'], $credential);
        $this->mock(ChatRealtimeChannel::class)->shouldReceive('webCredentialIsActive')->andReturn(true);
        $this->mock(Reader::class)->shouldReceive('read')->once()->andReturn(['count' => 1]);

        DB::enableQueryLog(); DB::flushQueryLog();
        $this->change(new Updates());
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        $selection = collect($queries)->first(fn ($query) => str_starts_with(strtolower($query['query']), 'select *')
            && str_contains($query['query'], 'admin_live_views'));
        $this->assertNotNull($selection);
        // Execute the actual selection: unrelated rows must stay in the database,
        // rather than crossing into PHP and being discarded there.
        $selectedIds = array_column(DB::select($selection['query'], $selection['bindings']), 'id');
        $this->assertEqualsCanonicalizing($matching, $selectedIds);
        Event::assertDispatchedTimes(AdminViewPatched::class, 2);
    }

    public function test_the_same_principal_is_revalidated_before_each_view_after_mid_flush_revocation(): void
    {
        $user = User::factory()->create(); $credential = str_repeat('b', 64);
        $ids = [$this->registerView($user, ['chat'], $credential), $this->registerView($user, ['chat'], $credential)];
        $this->mock(ChatRealtimeChannel::class)->shouldReceive('webCredentialIsActive')
            ->with($user->id, $credential)->twice()->andReturn(true, false);
        $this->mock(Reader::class)->shouldReceive('read')->once()->andReturn(['count' => 1]);

        $this->change(new Updates());
        $retained = DB::table('admin_live_views')->whereIn('id', $ids)->get();
        $this->assertCount(1, $retained);
        $this->assertSame(1, (int) $retained[0]->revision);
        $revokedId = array_values(array_diff($ids, [$retained[0]->id]))[0];
        Event::assertDispatched(AdminViewPatched::class, fn ($event) => $event->viewId === $revokedId && ($event->frame['revoked'] ?? false));
        Event::assertNotDispatched(AdminViewPatched::class, fn ($event) => $event->viewId === $revokedId && isset($event->frame['chunk']));
    }

    public function test_validation_is_not_shared_across_other_sessions_or_users(): void
    {
        $owner = User::factory()->create(); $other = User::factory()->create();
        $first = str_repeat('c', 64); $second = str_repeat('d', 64);
        $allowed = $this->registerView($owner, ['chat'], $first);
        $otherSession = $this->registerView($owner, ['chat'], $second);
        $otherUser = $this->registerView($other, ['chat'], $first);
        $validation = $this->mock(ChatRealtimeChannel::class);
        $validation->shouldReceive('webCredentialIsActive')->with($owner->id, $first)->once()->andReturn(true);
        $validation->shouldReceive('webCredentialIsActive')->with($owner->id, $second)->once()->andReturn(false);
        $validation->shouldReceive('webCredentialIsActive')->with($other->id, $first)->once()->andReturn(false);
        $this->mock(Reader::class)->shouldReceive('read')->once()->andReturn(['count' => 1]);

        $this->change(new Updates());
        $this->assertDatabaseHas('admin_live_views', ['id' => $allowed, 'revision' => 1]);
        $this->assertDatabaseMissing('admin_live_views', ['id' => $otherSession]);
        $this->assertDatabaseMissing('admin_live_views', ['id' => $otherUser]);
    }

}
