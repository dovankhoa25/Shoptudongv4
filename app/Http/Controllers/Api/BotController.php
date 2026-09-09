<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApiBotResource;
use App\Models\Bot;
use App\Support\ApiCache;
use Illuminate\Http\Request;

class BotController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'server_id' => 'required|exists:servers,id',
            'type'      => 'required|in:selling_main,import_main',
        ]);

        $cacheKey = ApiCache::key(
            'bots',
            (int) $request->input('server_id'),
            (string) $request->input('type')
        );

        return ApiCache::remember('public:bots', $cacheKey, 180, function () use ($request) {
            $bots = Bot::query()
            ->where('server_id', $request->server_id)
            ->where('type', $request->type)
            ->where('status', true)
            ->get();

            return [
                'success' => true,
                'data'    => ApiBotResource::collection($bots),
            ];
        });
    }
}
