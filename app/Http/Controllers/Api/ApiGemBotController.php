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
            'server_id' => 'required|exists:servers,id',
        ]);

        $serverId = (int) $request->server_id;
        $cacheKey = ApiCache::key('gembot', 'server', $serverId, 'active');

        return ApiCache::remember('public:gembot', $cacheKey, 120, function () use ($serverId) {
            $bots = GemBot::query()
                ->where('server_id', $serverId)
                ->where('status', true)
                ->get();

            return [
                'success' => true,
                'data' => ApiGemBotResource::collection($bots),
            ];
        });
    }
}
