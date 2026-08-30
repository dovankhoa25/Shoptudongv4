<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApiGemServerResource;
use App\Http\Resources\Api\ApiServerResource;
use App\Http\Resources\Api\ServerResource;
use App\Models\Server;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function index(Request $request)
    {
        // Lấy toàn bộ server + gold_prices đang active
        $servers = Server::with(['goldPrices' => function ($query) {
            $query->where('status', true);
        }])->get();

        return response()->json([
            'success' => true,
            'data'    => ApiServerResource::collection($servers),
        ]);
    }

    public function getGem(Request $request)
    {
        // Lấy toàn bộ server + gold_prices đang active
        $servers = Server::with(['gemPrices' => function ($query) {
            $query->where('status', true);
        }])->get();

        return response()->json([
            'success' => true,
            'data'    => ApiGemServerResource::collection($servers),
        ]);
    }
}
