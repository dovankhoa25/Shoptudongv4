<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApiBotResource;
use App\Http\Resources\Api\ApiGemBotResource;
use App\Models\Bot;
use App\Models\GemBot;
use App\Support\ApiCache;
use Illuminate\Http\JsonResponse;

class BotOverviewController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // Share invalidation with legacy endpoints, including bulk admin/app writes.
        // Do not wrap these in another cache: it could survive a bot mutation.
        $gold = ApiCache::rememberJson('public:bots', 'overview:gold:v1', 180, function () {
            $bots = Bot::query()->withoutChecker()->leftJoin('servers','servers.id','=','bots.server_id')->where('bots.status', true)
                ->where(fn($q)=>$q->withRole('selling_main')->orWhere(fn($q)=>$q->withRole('import_main')))->orderBy('bots.id')
                ->get(['bots.id', 'bots.name', 'bots.server_id', 'bots.type', 'bots.map_name', 'bots.map_id', 'bots.area_number', 'bots.status', 'bots.radar_qty', 'bots.bag_capacity', 'bots.bag_free_slots', 'servers.gold_radar_enabled as radar_enabled']);

            $radarIds=$bots->filter(fn($bot)=>(bool)$bot->radar_enabled)->pluck('server_id')->unique();
            $radarServers = $radarIds->isEmpty()?collect():\App\Models\Server::whereIn('id',$radarIds)->with('goldTradingPolicies')->get();
            $radarBots = $radarServers->isEmpty() ? collect() : Bot::whereIn('server_id', $radarServers->pluck('id'))->with('loginServer')->get();
            return [
                ...($radarServers->isEmpty() ? [] : ['radar_servers' => $radarServers->map(fn($server)=>app(\App\Services\RadarTradingService::class)->summary($server,$radarBots->where('server_id',$server->id)->values()))->values()]),
                'selling_main' => ApiBotResource::collection($bots->filter(fn($b)=>in_array('selling_main',\App\Services\TradingV3\GoldTradingService::types($b),true))->values()),
                'import_main' => ApiBotResource::collection($bots->filter(fn($b)=>in_array('import_main',\App\Services\TradingV3\GoldTradingService::types($b),true))->values()),
            ];
        });
        $gems = ApiCache::rememberJson('public:gembot', 'overview:gems:v3', 10, function () {
            return ApiGemBotResource::collection(GemBot::query()->where('status', true)->orderBy('id')
                ->get(['id', 'name', 'server_id', 'map_name', 'map_id', 'area_number', 'coordinates', 'status', 'gem_qty', 'gift_ticket_qty', 'consign_item_qty']))
                ->resolve();
        });

        // Client manages freshness; browser/proxy caches must not mask invalidation.
        return response()->json(['success' => true, 'data' => [...$gold, 'gem_selling' => $gems]])
            ->header('Cache-Control', 'no-store');
    }
}
