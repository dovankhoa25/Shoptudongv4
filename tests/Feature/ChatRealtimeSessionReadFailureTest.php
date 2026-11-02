<?php

namespace Tests\Feature;

use App\Exceptions\ChatRealtimeValidationUnavailable;
use App\Models\{ChatRealtimeSession, User};
use App\Services\Chat\ChatRealtimeChannel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\{Auth, Crypt, DB, Schema};
use Mockery;
use RuntimeException;
use SessionHandlerInterface;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

class ChatRealtimeSessionReadFailureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private ChatRealtimeSession $lease;
    private string $guardKey;
    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['access_security.admin_approval_required' => false]);
        $this->guardKey = Auth::guard('web')->getName();
        $this->sessionId = str_repeat('s', 40);
        $this->user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->lease = ChatRealtimeSession::create([
            'user_id' => $this->user->id,
            'credential_hash' => str_repeat('a', 64),
            'session_locator' => Crypt::encryptString($this->sessionId),
            'last_seen_at' => now(), 'expires_at' => now()->addHour(),
        ]);
    }

    private function useHandler(SessionHandlerInterface $handler): void
    {
        config(['session.driver' => 'file']);
        $manager = Mockery::mock();
        $manager->shouldReceive('driver')->andReturn(new Store('read-failure-test', $handler));
        $this->app->instance('session', $manager);
    }

    private function active(): bool
    {
        return app(ChatRealtimeChannel::class)->webCredentialIsActive($this->user->id, $this->lease->credential_hash);
    }

    public function test_handler_failure_is_temporary_and_the_existing_lease_recovers(): void
    {
        $handler = Mockery::mock(SessionHandlerInterface::class);
        $handler->shouldReceive('read')->with($this->sessionId)->once()->ordered()
            ->andThrow(new RuntimeException('Simulated session backend outage'));
        $handler->shouldReceive('read')->with($this->sessionId)->once()->ordered()
            ->andReturn(serialize([$this->guardKey => $this->user->id]));
        $this->useHandler($handler);

        try {
            $this->active();
            $this->fail('A storage error must not be reported as a known-invalid credential.');
        } catch (ChatRealtimeValidationUnavailable $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame('5', $exception->getHeaders()['Retry-After']);
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
        $this->assertNull($this->lease->fresh()->revoked_at);
        $this->assertTrue($this->active());
    }

    public function test_database_read_failure_is_temporary_and_recovers_after_storage_is_available(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config(['session.driver' => 'database', 'session.connection' => null, 'session.table' => 'sessions']);
        Schema::drop('sessions');

        try {
            $this->active();
            $this->fail('A database read error must not revoke a valid lease.');
        } catch (ChatRealtimeValidationUnavailable $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertInstanceOf(\Illuminate\Database\QueryException::class, $exception->getPrevious());
        }
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary(); $table->integer('last_activity'); $table->text('payload');
        });
        DB::table('sessions')->insert(['id' => $this->sessionId, 'last_activity' => now()->getTimestamp(),
            'payload' => base64_encode(serialize([$this->guardKey => $this->user->id]))]);
        $this->assertNull($this->lease->fresh()->revoked_at);
        $this->assertTrue($this->active());
    }

    public function test_false_handler_read_is_temporary_while_an_empty_string_remains_missing(): void
    {
        $handler = Mockery::mock(SessionHandlerInterface::class);
        $handler->shouldReceive('read')->with($this->sessionId)->times(3)
            ->andReturn(false, '', serialize([$this->guardKey => $this->user->id]));
        $this->useHandler($handler);

        try {
            $this->active();
            $this->fail('A false handler result signals a read failure, not a missing session.');
        } catch (ChatRealtimeValidationUnavailable $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
        $this->assertFalse($this->active());
        $this->assertNull($this->lease->fresh()->revoked_at);
        $this->assertTrue($this->active());
    }

    public function test_malformed_session_locator_is_invalid_without_reading_storage(): void
    {
        $handler = Mockery::mock(SessionHandlerInterface::class);
        $handler->shouldNotReceive('read');
        $this->useHandler($handler);
        $this->lease->update(['session_locator' => 'invalid-encrypted-locator']);
        $this->assertFalse($this->active());
    }

    public function test_invalid_encrypted_payload_remains_invalid(): void
    {
        $handler = Mockery::mock(SessionHandlerInterface::class);
        $handler->shouldReceive('read')->with($this->sessionId)->once()->andReturn('invalid-encrypted-payload');
        $this->useHandler($handler);
        config(['session.encrypt' => true]);
        $this->assertFalse($this->active());
    }

    public function test_missing_malformed_and_wrong_user_sessions_remain_invalid(): void
    {
        $handler = Mockery::mock(SessionHandlerInterface::class);
        $handler->shouldReceive('read')->with($this->sessionId)->times(3)
            ->andReturn('', 'malformed-serialized-session', serialize([$this->guardKey => $this->user->id + 1]));
        $this->useHandler($handler);
        $this->assertFalse($this->active());
        $this->assertFalse($this->active());
        $this->assertFalse($this->active());
    }

    public function test_unavailable_web_recipient_does_not_suppress_valid_api_recipients(): void
    {
        $client = app(ClientRepository::class)->createPasswordGrantClient('Read failure test', 'users');
        $token = Passport::token()->newQuery()->create([
            'id' => str_repeat('t', 80), 'user_id' => $this->user->id, 'client_id' => $client->id,
            'scopes' => ['chat:read'], 'revoked' => false, 'expires_at' => now()->addHour(),
        ]);
        $handler = Mockery::mock(SessionHandlerInterface::class);
        $handler->shouldReceive('read')->with($this->sessionId)->once()
            ->andThrow(new RuntimeException('Simulated web session backend outage'));
        $this->useHandler($handler);

        $channels = app(ChatRealtimeChannel::class)->privateChannelsFor([$this->user->id]);
        $credential = hash_hmac('sha256', 'api:'.$token->id, (string) config('app.key'));
        $this->assertCount(1, $channels);
        $this->assertSame('private-'.ChatRealtimeChannel::name($this->user->id, 'api-'.$credential), (string) $channels[0]);
        $this->assertNull($this->lease->fresh()->revoked_at);
    }
}
