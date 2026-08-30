<?php

namespace App\Http\Controllers\AppAuto\VersionTwo;

use App\Helpers\AccountEncrypt;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppAuto\VersionTwo\AppBotVersionTwoResource;
use App\Models\Bot;
use App\Models\GoldTransaction;
use App\Models\Transaction;
use App\Services\BotHistoryService;
use App\Services\InventoryMovementService;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AppBotVersionTwoController extends Controller
{

    public function index(Request $request)
    {
        $bots = Bot::query()
            ->when($request->filled('server_id'), fn($q) => $q->where('server_id', $request->server_id))
            ->when($request->filled('type'), fn($q) => $q->where('type', $request->type))
            ->where('status', true)
            ->whereHas('server', function ($q) {
                $q->where('status', true);
            })
            ->get();


        return AppBotVersionTwoResource::collection($bots)
            ->additional([
                'success' => true,
            ]);
    }



    public function store(Request $request)
    {
        $request->validate([
            'name' => 'nullable|string|max:255',
            'account_name' => 'required|string|max:255',
            'account_password' => 'required|string|max:255',
            'type' => 'required|string',
            'server_id' => 'required|exists:servers,id',
            'server_game_id' => 'nullable|integer|min:0',
            'gold_bar_qty' => 'nullable|integer|min:0',
            'gold_qty' => 'nullable|integer|min:0',
            'map_name' => 'nullable|string|max:255',
            'map_id' => 'required|string|max:255',
            'area_number' => 'required|string|max:255',
            'coordinates' => 'nullable|string|max:255',
            'status' => 'required|boolean',
        ]);

        $encryptedPassword = AccountEncrypt::encrypt($request->account_password);

        $bot = Bot::create([
            'name' => $request->name ?? null,
            'account_name' => $request->account_name,
            'account_password' => $request->account_password,
            'type' => $request->type,
            'server_id' => $request->server_id,
            'server_game_id' => $request->server_game_id,
            'gold_bar_qty' => $request->gold_bar_qty ?? 0,
            'gold_qty' => $request->gold_qty ?? 0,
            'map_name' => $request->map_name,
            'map_id' => $request->map_id,
            'area_number' => $request->area_number,
            'coordinates' => $request->coordinates,
            'status' => $request->status,
            'updated_by' => 'web',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'thêm ok',
            'data'    => $bot,
        ]);
    }

    // public function update(Request $request, $id)
    // {
    //     $bot = Bot::findOrFail($id);

    //     // if ($bot->updated_by !== 'web') {
    //     //     return response()->json([
    //     //         'success' => false,
    //     //         'message' => 'This bot is already synced or managed by app. Update denied.',
    //     //     ], 403);
    //     // }

    //     $request->validate([
    //         'gold_bar_qty' => 'nullable|integer|min:0',
    //         'gold_qty'     => 'nullable|integer|min:0',
    //         'map_name'     => 'nullable|string|max:255',
    //         'name'  => 'nullable|string|max:255',
    //         'transactions_id'  => 'nullable|integer',

    //     ]);

    //     $bot->update(array_merge(
    //         $request->only([
    //             'gold_bar_qty',
    //             'gold_qty',
    //             'map_name',
    //             'name',
    //         ]),
    //         [
    //             'updated_by'     => 'web',
    //             'last_synced_at' => now(),
    //         ]
    //     ));



    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Bot updated successfully',
    //         // 'data'    => $bot->fresh(),
    //         'transactions_id' => $request->transactions_id ?? null,
    //         'gold_bar_qty' => $request->gold_bar_qty,
    //         'gold_qty' => $request->gold_qty,
    //     ]);
    // }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'gold_bar_qty'    => 'nullable|integer|min:0',
            'gold_qty'        => 'nullable|integer|min:0',
            'map_name'        => 'nullable|string|max:255',
            'name'            => 'nullable|string|max:255',
            'transactions_id' => 'nullable|integer|exists:gold_transactions,id',
        ]);

        $updateData = array_merge(
            collect($validated)->only([
                'gold_bar_qty',
                'gold_qty',
                'map_name',
                'name',
            ])->toArray(),
            [
                'updated_by'     => 'app',
                'last_synced_at' => now(),
            ]
        );

        $bot = DB::transaction(function () use ($id, $updateData, $validated) {
            $bot = Bot::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            $oldData = $bot->only([
                'name',
                'gold_bar_qty',
                'gold_qty',
                'map_name',
                'updated_by',
                'last_synced_at',
            ]);
            $oldGoldQty = (int) $bot->gold_qty;
            $oldGoldBarQty = (int) $bot->gold_bar_qty;

            $bot->update($updateData);
            $bot->refresh();

            $newData = $bot->only([
                'name',
                'gold_bar_qty',
                'gold_qty',
                'map_name',
                'updated_by',
                'last_synced_at',
            ]);

            $transactionId = $validated['transactions_id'] ?? null;
            $transaction = $transactionId ? GoldTransaction::find($transactionId) : null;
            InventoryMovementService::recordGoldChange(
                bot: $bot,
                beforeGold: $oldGoldQty,
                beforeBars: $oldGoldBarQty,
                afterGold: (int) $bot->gold_qty,
                afterBars: (int) $bot->gold_bar_qty,
                movementType: $transaction?->type === 'import' ? 'import' : ($transactionId ? 'sale' : 'sync_correction'),
                source: 'app',
                transactionId: $transactionId,
                transactionType: $transactionId ? 'gold_transaction' : null,
                idempotencyKey: $transactionId ? "gold_transaction:{$transactionId}:bot:{$bot->id}" : null,
                note: $transactionId ? 'Converted gold synced for transaction' : 'Converted gold synced without transaction'
            );

            BotHistoryService::logUpdate(
                entityType: 'bot',
                model: $bot,
                oldData: $oldData,
                newData: $newData,
                source: 'app',
                category: 'runtime',
                adminUserId: null,
                transactionId: $validated['transactions_id'] ?? null,
                transactionType: 'gold_transaction',
                note: 'Bot synced from app/api'
            );

            return $bot->fresh();
        });

        return response()->json([
            'success'         => true,
            'message'         => 'Bot updated successfully',
            'transactions_id' => $validated['transactions_id'] ?? null,
            'gold_bar_qty'    => $validated['gold_bar_qty'] ?? null,
            'gold_qty'        => $validated['gold_qty'] ?? null,
        ]);
    }
}
