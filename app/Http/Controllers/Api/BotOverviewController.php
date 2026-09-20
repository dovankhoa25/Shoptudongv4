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
            $bots = Bot::query()->where('status', true)
                ->whereIn('type', ['selling_main', 'import_main'])->orderBy('id')
                ->get(['id', 'name', 'server_id', 'type', 'map_name', 'map_id', 'area_number', 'status']);

            return [
                'selling_main' => ApiBotResource::collection($bots->where('type', 'selling_main')->values()),
                'import_main' => ApiBotResource::collection($bots->where('type', 'import_main')->values()),
            ];
        });
        $gems = ApiCache::rememberJson('public:gembot', 'overview:gems:v1', 120, function () {
            return ApiGemBotResource::collection(GemBot::query()->where('status', true)->orderBy('id')
                ->get(['id', 'name', 'server_id', 'map_name', 'map_id', 'area_number', 'coordinates', 'status']))
                ->resolve();
        });

        // Client manages freshness; browser/proxy caches must not mask invalidation.
        return response()->json(['success' => true, 'data' => [...$gold, 'gem_selling' => $gems]])
            ->header('Cache-Control', 'no-store');
    }
}
