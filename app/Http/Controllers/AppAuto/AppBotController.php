<?php

namespace App\Http\Controllers\AppAuto;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppAuto\AppBotResource;
use App\Models\Bot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AppBotController extends Controller
{


    public function index(Request $request)
    {
        $bots = Bot::query()
            ->when($request->filled('server_id'), fn($q) => $q->where('server_id', $request->server_id))
            ->when($request->filled('type'), fn($q) => $q->where('type', $request->type))
            ->where('status', true) // bot status
            ->whereHas('server', function ($q) {
                $q->where('status', true); // server status
            })
            ->get();

        return response()->json([
            'success' => true,
            'data' => AppBotResource::collection($bots),
        ]);
    }





    public function update(Request $request, $id)
    {
        $bot = Bot::findOrFail($id);

        // if ($bot->updated_by !== 'web') {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'This bot is already synced or managed by app. Update denied.',
        //     ], 403);
        // }

        $request->validate([
            'gold_bar_qty' => 'nullable|integer|min:0',
            'gold_qty'     => 'nullable|integer|min:0',
            'map_name'     => 'nullable|string|max:255',
            'name'  => 'nullable|string|max:255',
        ]);

        $bot->update(array_merge(
            $request->only([
                'gold_bar_qty',
                'gold_qty',
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
            'data'    => $bot->fresh(), // Lấy lại bản mới từ DB
        ]);
    }
}
