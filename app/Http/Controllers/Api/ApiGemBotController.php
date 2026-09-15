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

        return ApiCache::rememberJson('public:gembot', $cacheKey, 120, function () use ($serverId, $request) {
            $request->validate(['server_id' => 'exists:servers,id']);
            $bots = GemBot::query()
                ->where('server_id', $serverId)
                ->where('status', true)
                ->get(['id', 'name', 'server_id', 'map_name', 'map_id', 'area_number', 'coordinates', 'status']);

            return [
                'success' => true,
                'data' => ApiGemBotResource::collection($bots),
            ];
        });
    }
}
