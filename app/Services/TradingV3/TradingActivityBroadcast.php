<?php

namespace App\Services\TradingV3;

use Ably\AblyRest;
use Ably\Models\Message;
use App\Events\{TradingBotActivity, UserEvent};
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Broadcasters\AblyBroadcaster;
use Illuminate\Support\Carbon;

/** Worker-only network transport; preserves Laravel Echo's channel/message envelope. */
class TradingActivityBroadcast
{
    private ?AblyRest $ably = null;

    protected function client(AblyBroadcaster $broadcaster): AblyRest
    {
        // Isolate short realtime timeouts from all financial broadcast clients.
        return $this->ably ??= new AblyRest([...get_object_vars($broadcaster->getAbly()->options),
            'httpOpenTimeout' => 1500, 'httpRequestTimeout' => 4000,
            'httpMaxRetryCount' => 0, 'httpMaxRetryDuration' => 4000]);
    }

    public function publishPublic(array $activities): void
    {
        if (!$activities) return;
        $broadcaster = app(BroadcastManager::class)->connection();
        if (!$broadcaster instanceof AblyBroadcaster) {
            foreach ($activities as $activity) if (Carbon::parse($activity['expires_at'])->isFuture()) event(new TradingBotActivity($activity));
            return;
        }
        $channel = $this->client($broadcaster)->channels->get('public:Trading.Bots');
        foreach (array_chunk($activities, 100) as $chunk) {
            $messages = [];
            foreach ($chunk as $activity) {
                if (!Carbon::parse($activity['expires_at'])->isFuture()) continue;
                $message = new Message(); $message->name = 'TradingBotActivity'; $message->data = $activity;
                $messages[] = $message;
            }
            if ($messages) $channel->publish($messages);
        }
    }

    public function publishPrivate(array $notice): void
    {
        if (!Carbon::parse($notice['payload']['expires_at'])->isFuture()) return;
        $event = new UserEvent($notice['user_id'], 'bot_activity', $notice['message'], $notice['payload']);
        $broadcaster = app(BroadcastManager::class)->connection();
        if (!$broadcaster instanceof AblyBroadcaster) { event($event); return; }
        $message = new Message(); $message->name = $event->broadcastAs(); $message->data = $event->broadcastWith();
        $this->client($broadcaster)->channels->get('private:User.'.$notice['user_id'])->publish($message);
    }
}
