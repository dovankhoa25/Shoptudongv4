<?php

namespace App\Http\Controllers\AppAuto;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppAuto\AppBotResource;
use App\Http\Resources\AppAuto\AppGemBotResource;
use App\Models\Bot;
use App\Models\GemBot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AppGemBotController extends Controller
{


    public function index(Request $request)
    {
        $bots = GemBot::query()
            ->when($request->filled('server_id'), fn($q) => $q->where('server_id', $request->server_id))
            ->when($request->filled('type'), fn($q) => $q->where('type', $request->type))
            ->where('status', true) // bot status
            ->whereHas('server', function ($q) {
                $q->where('status', true); // server status
            })
            ->get();

        return response()->json([
            'success' => true,
            'data' => AppGemBotResource::collection($bots),
        ]);
    }





    public function update(Request $request, $id)
    {
        $bot = GemBot::findOrFail($id);

        // if ($bot->updated_by !== 'web') {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'This bot is already synced or managed by app. Update denied.',
        //     ], 403);
        // }

        $request->validate([
            'gem_qty'     => 'nullable|integer|min:0',
            'map_name'     => 'nullable|string|max:255',
            'name'  => 'nullable|string|max:255',

        ]);

        $bot->update(array_merge(
            $request->only([
                'gem_qty',
                'map_name',
                'name',
            ]),
            [
                'updated_by'     => 'web',
                'last_synced_at' => now(),
            ]
        ));

        return response()->json([
            'success' => true,
            'message' => 'Bot updated successfully',
            'data'    => $bot->fresh(),
        ]);
    }
}
