<?php

namespace Tests\Unit;

use Ably\AblyRest;
use App\Services\TradingV3\TradingActivityBroadcast;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Broadcasters\AblyBroadcaster;
use Tests\TestCase;

class RecordingActivityAbly extends AblyRest
{
    public array $calls = [];
    public function post($url, $headers = [], $params = [], $returnHeaders = false, $auth = true)
    {
        $this->calls[] = ['url' => $url, 'body' => json_decode($params, true)];
        return [];
    }
}

class TradingActivityBroadcastTest extends TestCase
{
    private RecordingActivityAbly $client;
    private TradingActivityBroadcast $broadcast;

    protected function setUp(): void
    {
        parent::setUp(); $this->freezeTime();
        $this->client = new RecordingActivityAbly(['key' => 'dummy.key:dGVzdA==', 'useBinaryProtocol' => false]);
        $manager = \Mockery::mock(BroadcastManager::class);
        $manager->shouldReceive('connection')->andReturn(new AblyBroadcaster($this->client));
        $this->app->instance(BroadcastManager::class, $manager);
        $this->broadcast = new class($this->client) extends TradingActivityBroadcast {
            public function __construct(private AblyRest $recordingClient) {}
            protected function client(AblyBroadcaster $broadcaster): AblyRest { return $this->recordingClient; }
        };
    }

    private function activity(int $id): array
    {
        return ['bot_type' => 'gem_bot', 'bot_id' => $id, 'server_id' => 6, 'code' => 'ready', 'text' => 'Sẵn sàng',
            'online_seconds' => 60, 'observed_at' => now()->toIso8601String(), 'expires_at' => now()->addSeconds(35)->toIso8601String()];
    }

    public function test_forty_activities_are_one_real_sdk_request_with_unchanged_echo_messages(): void
    {
        $activities = array_map(fn($id) => $this->activity($id), range(1, 40));
        $this->broadcast->publishPublic($activities);
        $this->assertCount(1, $this->client->calls);
        $this->assertSame('/channels/public%3ATrading.Bots/messages', $this->client->calls[0]['url']);
        $messages = $this->client->calls[0]['body']; $this->assertCount(40, $messages);
        foreach ($messages as $i => $message) {
            $this->assertSame('TradingBotActivity', $message['name']);
            $this->assertSame('json', $message['encoding']);
            $this->assertSame($activities[$i], json_decode($message['data'], true));
        }
    }

    public function test_large_batches_are_bounded_and_expired_frames_are_not_sent(): void
    {
        $activities = array_map(fn($id) => $this->activity($id), range(1, 101));
        $this->broadcast->publishPublic($activities);
        $this->assertCount(2, $this->client->calls);
        $this->assertCount(100, $this->client->calls[0]['body']);
        $this->assertSame(101, json_decode($this->client->calls[1]['body']['data'], true)['bot_id']);
        $this->travel(36)->seconds();
        $this->broadcast->publishPublic($activities);
        $this->assertCount(2, $this->client->calls);
    }

    public function test_private_notice_keeps_its_channel_and_user_event_envelope(): void
    {
        $notice = ['user_id' => 123, 'message' => 'private message', 'payload' => ['bot_id' => 456, 'order_id' => 789,
            'code' => 'name_mismatch', 'expires_at' => now()->addSeconds(35)->toIso8601String()]];
        $this->broadcast->publishPrivate($notice);
        $this->assertSame('/channels/private%3AUser.123/messages', $this->client->calls[0]['url']);
        $message = $this->client->calls[0]['body']; $this->assertSame('UserEvent', $message['name']);
        $this->assertSame(['userId' => 123, 'type' => 'bot_activity', 'message' => 'private message', 'payload' => $notice['payload']],
            json_decode($message['data'], true));
    }

    public function test_activity_timeouts_do_not_mutate_the_shared_financial_broadcaster_client(): void
    {
        $service = new class extends TradingActivityBroadcast {
            public function isolated(AblyBroadcaster $driver): AblyRest { return parent::client($driver); }
        };
        $driver = new AblyBroadcaster($this->client); $client = $service->isolated($driver);
        $this->assertSame(1500, $client->options->httpOpenTimeout);
        $this->assertSame(4000, $client->options->httpRequestTimeout);
        $this->assertSame(0, $client->options->httpMaxRetryCount);
        $this->assertSame(10000, $driver->getAbly()->options->httpRequestTimeout);
        $this->assertSame(3, $driver->getAbly()->options->httpMaxRetryCount);
    }
}
