<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApiBotResource;
use App\Http\Resources\Api\ApiGemBotResource;
use App\Models\GemBot;
use Illuminate\Http\Request;

class ApiGemBotController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'server_id' => 'required|exists:servers,id',
        ]);

        $bots = GemBot::query()
            ->where('server_id', $request->server_id)
            ->where('status', true)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => ApiGemBotResource::collection($bots),
        ]);
    }
}
