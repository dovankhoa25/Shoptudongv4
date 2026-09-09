<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApiGemServerResource;
use App\Http\Resources\Api\ApiServerResource;
use App\Http\Resources\Api\ServerResource;
use App\Models\Server;
use App\Support\ApiCache;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function index(Request $request)
    {
        return ApiCache::remember(
            'public:servers',
            ApiCache::key('servers', 'gold-prices'),
            180,
            function () {
                $servers = Server::with(['goldPrices' => function ($query) {
                    $query->where('status', true);
                }])->get();

                return [
                    'success' => true,
                    'data'    => ApiServerResource::collection($servers),
                ];
            }
        );
    }

    public function getGem(Request $request)
    {
        return ApiCache::remember(
            'public:servers',
            ApiCache::key('servers', 'gem-prices'),
            180,
            function () {
                $servers = Server::with(['gemPrices' => function ($query) {
                    $query->where('status', true);
                }])->get();

                return [
                    'success' => true,
                    'data'    => ApiGemServerResource::collection($servers),
                ];
            }
        );
    }
}
