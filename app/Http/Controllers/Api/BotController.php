<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApiBotResource;
use App\Models\Bot;
use Illuminate\Http\Request;

class BotController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'server_id' => 'required|exists:servers,id',
            'type'      => 'required|in:selling_main,import_main',
        ]);


        $bots = Bot::query()
            ->where('server_id', $request->server_id)
            ->where('type', $request->type)
            ->where('status', true)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => ApiBotResource::collection($bots),
        ]);
    }
}
