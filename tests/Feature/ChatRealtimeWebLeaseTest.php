<?php

namespace Tests\Feature;

use App\Models\ChatRealtimeSession;
use App\Models\User;
use App\Services\Chat\ChatRealtimeChannel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

class ChatRealtimeWebLeaseTest extends TestCase
{
    private const CONNECTION = 'chat_web_lease_test';

    private SQLiteConnection $connection;

    private Request $request;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh in-memory database only. Model MySQL's changed-row count so
        // the ordinary SQLite suite cannot hide same-second no-op updates.
        $this->connection = new class(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']) extends SQLiteConnection
        {
            public int $noOpUpdates = 0;

            public function affectingStatement($query, $bindings = [])
            {
                $isLeaseUpdate = str_starts_with($query, 'update "chat_realtime_sessions" set');
                $before = $isLeaseUpdate
                    ? $this->select('select * from "chat_realtime_sessions" order by "id"')
                    : null;
                $affected = parent::affectingStatement($query, $bindings);

                if ($isLeaseUpdate && $affected > 0
                    && $before == $this->select('select * from "chat_realtime_sessions" order by "id"')) {
                    $this->noOpUpdates++;

                    return 0;
                }

                return $affected;
            }
        };
        DB::extend(self::CONNECTION, fn () => $this->connection);
        config()->set('database.connections.'.self::CONNECTION, ['driver' => self::CONNECTION]);
        DB::setDefaultConnection(self::CONNECTION);
        config()->set('session.lifetime', 120);
        $this->freezeTime();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->timestamp('locked_until')->nullable();
            $table->softDeletes();
        });
        Schema::create('chat_realtime_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->char('credential_hash', 64)->unique();
            $table->text('session_locator');
            $table->dateTime('last_seen_at');
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });
        DB::table('users')->insert(['id' => 1, 'status' => User::STATUS_ACTIVE]);
        $this->user = User::query()->findOrFail(1);
        $this->request = Request::create('/broadcasting/auth', 'POST');
        $this->request->setLaravelSession(new Store('test-chat', new ArraySessionHandler(120)));
        $this->request->setUserResolver(fn () => $this->user);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DB::purge(self::CONNECTION);

        parent::tearDown();
    }

    public function test_new_and_rechecked_web_leases_survive_same_second_no_op_updates(): void
    {
        $service = app(ChatRealtimeChannel::class);
        $channel = $service->currentForRequest($this->request);

        $this->assertIsString($channel);
        $this->assertSame(1, $this->connection->noOpUpdates);
        // HandleInertiaRequests resolves first, then broadcasting auth refreshes
        // the same request. Both must return the same channel within one second.
        $this->assertSame($channel, $service->currentForRequest($this->request, refresh: true));
        $this->assertSame(2, $this->connection->noOpUpdates);
        $this->assertSame(1, ChatRealtimeSession::query()->count());
    }

    public function test_revoked_web_lease_is_not_reactivated_by_the_zero_update_fallback(): void
    {
        $service = app(ChatRealtimeChannel::class);
        $this->assertIsString($service->currentForRequest($this->request));
        ChatRealtimeSession::query()->update(['revoked_at' => now()]);

        $this->assertNull($service->currentForRequest($this->request, refresh: true));
        $this->assertNotNull(ChatRealtimeSession::query()->firstOrFail()->revoked_at);
    }

    public function test_lease_owned_by_another_user_is_rejected_when_update_matches_no_rows(): void
    {
        $service = app(ChatRealtimeChannel::class);
        $this->assertIsString($service->currentForRequest($this->request));
        ChatRealtimeSession::query()->update(['user_id' => 2]);

        $this->assertNull($service->currentForRequest($this->request, refresh: true));
        $this->assertSame(2, (int) ChatRealtimeSession::query()->firstOrFail()->user_id);
    }

    public function test_expired_lease_can_be_renewed_by_the_authenticated_web_session(): void
    {
        $service = app(ChatRealtimeChannel::class);
        $channel = $service->currentForRequest($this->request);
        $this->assertIsString($channel);
        ChatRealtimeSession::query()->update(['expires_at' => now()->subSecond()]);

        $this->assertSame($channel, $service->currentForRequest($this->request, refresh: true));
        $this->assertTrue(ChatRealtimeSession::query()->firstOrFail()->expires_at->isFuture());
        $this->assertSame($channel, $service->currentForRequest($this->request, refresh: true));
    }
}
