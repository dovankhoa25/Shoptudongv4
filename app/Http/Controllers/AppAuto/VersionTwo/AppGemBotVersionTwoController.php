<?php

namespace App\Http\Controllers\AppAuto\VersionTwo;

use App\Http\Controllers\Controller;

use App\Http\Resources\AppAuto\VersionTwo\AppGemBotVersionTwoResource;
use App\Models\GemBot;
use App\Services\BotHistoryService;
use App\Services\InventoryMovementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppGemBotVersionTwoController extends Controller
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


        return AppGemBotVersionTwoResource::collection($bots)
            ->additional([
                'success' => true,
            ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'account_name' => 'required|string|max:255|unique:gem_bots,account_name',
            'account_password' => 'required|string|max:255',
            'server_id' => 'required|exists:servers,id',
            'server_game_id' => 'nullable|integer|min:0',
            'gem_qty' => 'nullable|integer|min:0',
            'map_name' => 'nullable|string|max:255',
            'map_id' => 'required|string|max:255',
            'area_number' => 'required|string|max:255',
            'coordinates' => 'nullable|string|max:255',
            'status' => 'required|boolean',
        ]);

        $gemBot = GemBot::create([
            'name' => $validated['name'] ?? null,
            'account_name' => $validated['account_name'],
            'account_password' => $validated['account_password'], // Consider encrypting
            'server_id' => $validated['server_id'] ?? 1,
            'server_game_id' => $validated['server_game_id'],
            'gem_qty' => $validated['gem_qty'] ?? 0,
            'map_name' => $validated['map_name'],
            'map_id' => $validated['map_id'],
            'area_number' => $validated['area_number'],
            'coordinates' => $validated['coordinates'],
            'status' => $validated['status'],
            'updated_by' => 'web',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'thêm ok',
            'data'    => $gemBot,
        ]);
    }





    // public function update(Request $request, $id)
    // {
    //     $bot = GemBot::findOrFail($id);

    //     $request->validate([
    //         'gem_qty'     => 'nullable|integer|min:0',
    //         'map_name'     => 'nullable|string|max:255',
    //         'name'  => 'nullable|string|max:255',
    //         'transactions_id'  => 'nullable|integer',
    //         'item'  => 'nullable|string|max:255',

    //     ]);

    //     $bot->update(array_merge(
    //         $request->only([
    //             'gem_qty',
    //             'map_name',
    //             'name',
    //             'item',
    //         ]),
    //         [
    //             'updated_by'     => 'web',
    //             'last_synced_at' => now(),
    //         ]
    //     ));

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Bot updated successfully',
    //         'data'    => $bot->fresh(),
    //         'transactions_id'    =>  $request->transactions_id,
    //     ]);
    // }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'gem_qty'         => 'nullable|integer|min:0',
            'map_name'        => 'nullable|string|max:255',
            'name'            => 'nullable|string|max:255',
            'transactions_id' => 'nullable|integer|exists:gem_transactions,id',
        ]);

        $updateData = array_merge(
            collect($validated)->only([
                'gem_qty',
                'map_name',
                'name',
            ])->toArray(),
            [
                'updated_by'     => 'app',
                'last_synced_at' => now(),
            ]
        );

        $bot = DB::transaction(function () use ($id, $updateData, $validated) {
            $bot = GemBot::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            $oldData = $bot->only([
                'name',
                'gem_qty',
                'map_name',
                'updated_by',
                'last_synced_at',
            ]);
            $oldGemQty = (int) $bot->gem_qty;

            $bot->update($updateData);
            $bot->refresh();

            $newData = $bot->only([
                'name',
                'gem_qty',
                'map_name',
                'updated_by',
                'last_synced_at',
            ]);

            $transactionId = $validated['transactions_id'] ?? null;
            InventoryMovementService::recordGemChange(
                bot: $bot,
                before: $oldGemQty,
                after: (int) $bot->gem_qty,
                movementType: $transactionId ? 'sale' : 'sync_correction',
                source: 'app',
                transactionId: $transactionId,
                transactionType: $transactionId ? 'gem_transaction' : null,
                idempotencyKey: $transactionId ? "gem_transaction:{$transactionId}:gem_bot:{$bot->id}" : null,
                note: $transactionId ? 'Gem balance synced for completed order' : 'Gem balance synced without transaction'
            );

            BotHistoryService::logUpdate(
                entityType: 'gem_bot',
                model: $bot,
                oldData: $oldData,
                newData: $newData,
                source: 'app',
                category: 'runtime',
                adminUserId: null,
                transactionId: $validated['transactions_id'] ?? null,
                transactionType: 'gem_transaction',
                note: 'Gem bot synced from app/api'
            );

            return $bot->fresh();
        });

        return response()->json([
            'success'         => true,
            'message'         => 'Bot updated successfully',
            'data'            => $bot->fresh(),
            'transactions_id' => $validated['transactions_id'] ?? null,
        ]);
    }
}
