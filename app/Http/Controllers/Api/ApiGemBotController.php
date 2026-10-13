<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApiBotResource;
use App\Http\Resources\Api\ApiGemBotResource;
use App\Models\GemBot;
use App\Support\ApiCache;
use Illuminate\Http\Request;

class ApiGemBotController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'server_id' => 'required|integer|min:1',
        ]);

        $serverId = (int) $request->server_id;
        $cacheKey = ApiCache::key('gembot', 'server', $serverId, 'active');
        // A cached positive ticket capacity must expire with its earliest lease, even when
        // same-owner renewals deliberately avoid invalidating otherwise unchanged cards.
        $leaseUntil = GemBot::query()->where('server_id', $serverId)->where('status', true)
            ->whereNotNull('v3_worker_id')->whereNotNull('gift_ticket_qty')
            ->where('v3_lease_until', '>', now())->min('v3_lease_until');
        $ttl = $leaseUntil ? min(120, max(1, \Illuminate\Support\Carbon::parse($leaseUntil)->getTimestamp() - now()->getTimestamp())) : 120;

        return ApiCache::rememberJson('public:gembot', $cacheKey, $ttl, function () use ($serverId, $request) {
            $request->validate(['server_id' => 'exists:servers,id']);
            $bots = GemBot::query()
                ->where('server_id', $serverId)
                ->where('status', true)
                ->get(['id', 'name', 'server_id', 'map_name', 'map_id', 'area_number', 'coordinates', 'status', 'gem_qty', 'gift_ticket_qty', 'consign_item_qty', 'v3_worker_id', 'v3_lease_until']);

            return [
                'success' => true,
                'data' => ApiGemBotResource::collection($bots),
            ];
        });
    }
}
