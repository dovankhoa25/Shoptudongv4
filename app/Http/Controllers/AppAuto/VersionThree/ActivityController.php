<?php

namespace App\Http\Controllers\AppAuto\VersionThree;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppAuto\VersionThree\ActivityBatchRequest;
use App\Models\{Bot, GemBot};
use App\Services\TradingV3\TradingActivityPublisher;

class ActivityController extends Controller
{
    public function batch(ActivityBatchRequest $request, TradingActivityPublisher $publisher)
    {
        $data = $request->validated(); $groups = collect($data['activities'])->groupBy('bot_type'); $bots = [];
        foreach (['gem_bot' => GemBot::class, 'bot' => Bot::class] as $type => $model) {
            $ids = ($groups[$type] ?? collect())->pluck('bot_id')->unique()->all();
            $bots[$type] = $ids ? $model::whereIn('id', $ids)->get(['id', 'server_id', 'v3_worker_id'])->keyBy('id') : collect();
        }
        $observed = now(); $expires = $observed->copy()->addSeconds(config('trading_v3.activity_ttl_seconds', 35))->toIso8601String();
        $accepted = []; $rejected = [];
        foreach ($data['activities'] as $activity) {
            $type = $activity['bot_type']; $id = (int) $activity['bot_id']; $bot = $bots[$type][$id] ?? null;
            $labels = TradingActivityPublisher::labels($type);
            if (!$bot || $bot->v3_worker_id !== $data['worker_id'] || !isset($labels[$activity['code']])) {
                $rejected[] = ['bot_type' => $type, 'bot_id' => $id, 'reason' => !$bot ? 'not_found' : ($bot->v3_worker_id !== $data['worker_id'] ? 'not_owner' : 'invalid_code')];
                continue;
            }
            $accepted[$type.':'.$id] = [...$activity, 'bot_id' => $id, 'server_id' => (int) $bot->server_id,
                'text' => $labels[$activity['code']], 'observed_at' => $observed->toIso8601String(), 'expires_at' => $expires];
        }
        $delivered = $publisher->publish(array_values($accepted));
        return response()->json(['success' => true, 'activity_delivered' => $delivered, 'accepted_count' => count($accepted), 'rejected' => $rejected]);
    }
}
