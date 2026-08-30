<?php

namespace App\Http\Controllers\AppAuto\VersionTwo;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppAuto\VersionTwo\AppGemTransactionVersionTwoResource;
use App\Models\GemBot;
use App\Models\GemTransaction;
use App\Models\InventoryMovement;
use App\Models\Transaction;
use App\Services\BotHistoryService;
use App\Services\InventoryMovementService;
use App\Services\TransactionHistoryService;
use App\Services\UserRealtimeNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppGemTransactionVersionTwoController extends Controller
{
    public function __construct(private readonly UserRealtimeNotifier $realtime) {}

    public function index(Request $request)
    {
        $query = GemTransaction::query()
            ->with(['server', 'user']) // Load relationships
            ->whereIn('updated_by', ['web', 'app'])
            ->whereIn('status', ['pending', 'processing']);
        // ->whereIn('status', ['pending']);

        // Lọc theo server_id nếu có
        if ($request->filled('server_id')) {
            $query->where('server_id', $request->server_id);
        }

        // Lọc theo status nếu có
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $transactions = $query->get();

        return AppGemTransactionVersionTwoResource::collection($transactions)
            ->additional([
                'success' => true,
            ]);
    }

    public function store(Request $request)
    {

        $request->validate([
            'server_id' => 'nullable|string|max:255',
            'server_game_id' => 'nullable|string|max:255',
            'character_name' => 'nullable|string|max:255',
            'gem_qty' => 'nullable|string|max:255',

        ]);
        $order = GemTransaction::create([
            'user_id' => 1,
            'server_id' => 1,
            'character_name' => $request->character_name,
            'amount_vnd' => 10000,
            'gem_qty' => $request->gem_qty,
            'price_at_transaction' => 10,
            'status' => 'pending',
            'updated_by' => 'web',
        ]);

        return response()->json([
            'success' => true,
            'data' => 'Transaction updated successfully.',
        ]);
    }

    // public function update(Request $request, $id)
    // {
    //     $gemTransaction = GemTransaction::findOrFail($id);

    //     $request->validate([
    //         'status' => 'required|in:processing,completed',
    //         'item' => 'nullable|string|max:255',
    //         'bot_id' => 'required|integer|min:0',
    //     ]);

    //     DB::transaction(function () use ($request, $gemTransaction) {
    //         // Khóa record để tránh race condition
    //         $gemTransaction->lockForUpdate();

    //         $currentStatus = $gemTransaction->status;
    //         $newStatus     = $request->status;
    //         $item     = $request->item;
    //         $botID     = $request->bot_id;

    //         // Kiểm tra luồng trạng thái hợp lệ
    //         $validTransitions = [
    //             GemTransaction::STATUS_PENDING    => [GemTransaction::STATUS_PROCESSING],
    //             GemTransaction::STATUS_PROCESSING => [GemTransaction::STATUS_COMPLETED],
    //         ];

    //         if (
    //             !isset($validTransitions[$currentStatus]) ||
    //             !in_array($newStatus, $validTransitions[$currentStatus], true)
    //         ) {
    //             abort(409, "Không thể chuyển từ trạng thái '{$currentStatus}' sang '{$newStatus}'.");
    //         }

    //         $gemTransaction->status = $newStatus;
    //         $gemTransaction->item = $item;
    //         $gemTransaction->updated_by = 'app';
    //         $gemTransaction->last_synced_at = now();
    //         $gemTransaction->save();
    //     });

    //     return response()->json([
    //         'success' => true,
    //         'message' => ucfirst($gemTransaction->type) . ' updated successfully.',
    //         'data'    => $gemTransaction->fresh(),
    //         'bot_id' => $request->bot_id,
    //     ]);
    // }

    // public function update(Request $request, $id)
    // {
    //     $validated = $request->validate([
    //         'status' => 'required|in:processing,completed',
    //         'item'   => 'nullable|string|max:255',
    //         'bot_id' => 'required|integer|min:0',
    //     ]);

    //     $result = DB::transaction(function () use ($id, $validated) {

    //         // 1) KHÓA GemTransaction row
    //         $gemTransaction = GemTransaction::query()
    //             ->whereKey($id)
    //             ->lockForUpdate()
    //             ->firstOrFail();

    //         $currentStatus = $gemTransaction->status;
    //         $newStatus     = $validated['status'];

    //         $validTransitions = [
    //             GemTransaction::STATUS_PENDING    => [GemTransaction::STATUS_PROCESSING],
    //             GemTransaction::STATUS_PROCESSING => [GemTransaction::STATUS_COMPLETED],
    //         ];

    //         if (
    //             !isset($validTransitions[$currentStatus]) ||
    //             !in_array($newStatus, $validTransitions[$currentStatus], true)
    //         ) {
    //             abort(409, "Không thể chuyển từ trạng thái '{$currentStatus}' sang '{$newStatus}'.");
    //         }

    //         // 2) Update info cơ bản
    //         $gemTransaction->status         = $newStatus;
    //         $gemTransaction->item           = $validated['item'] ?? null;
    //         $gemTransaction->updated_by     = 'app';
    //         $gemTransaction->last_synced_at = now();

    //         // Nếu bảng GemTransaction có cột bot_id thì lưu luôn:
    //         // $gemTransaction->bot_id = $validated['bot_id'];

    //         // 3) CHỈ trừ gem khi chuyển processing -> completed
    //         if (
    //             $currentStatus === GemTransaction::STATUS_PROCESSING &&
    //             $newStatus === GemTransaction::STATUS_COMPLETED
    //         ) {
    //             // KHÓA GemBot row
    //             $bot = GemBot::query()
    //                 ->whereKey($validated['bot_id'])
    //                 ->lockForUpdate()
    //                 ->firstOrFail();

    //             $needGem = (int) $gemTransaction->gem_qty; // gem cần trừ (lấy từ GemTransaction)
    //             $botGem  = (int) $bot->gem_qty;

    //             if ($needGem <= 0) {
    //                 abort(422, "gem_qty của giao dịch không hợp lệ.");
    //             }

    //             if ($botGem < $needGem) {
    //                 abort(409, "Bot không đủ ngọc. Bot đang có {$botGem}, cần {$needGem}.");
    //             }

    //             $bot->gem_qty        = $botGem - $needGem;
    //             $bot->updated_by     = 'app';
    //             $bot->last_synced_at = now();
    //             $bot->save();
    //         }

    //         $gemTransaction->save();

    //         return $gemTransaction->fresh();
    //     });

    //     return response()->json([
    //         'success' => true,
    //         'message' => ucfirst($result->type) . ' updated successfully.',
    //         'data'    => $result,
    //         'bot_id'  => (int) $validated['bot_id'],
    //     ]);
    // }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:processing,completed',
            'item' => 'nullable|string|max:255',
            'bot_id' => 'required|integer|min:1',
        ]);

        $result = DB::transaction(function () use ($id, $validated) {

            $gemTransaction = GemTransaction::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            $oldTransactionData = $gemTransaction->historySnapshot();

            $currentStatus = $gemTransaction->status;
            $newStatus = $validated['status'];

            if ($currentStatus === $newStatus) {
                return $gemTransaction->fresh();
            }

            $refundGrace = max((int) config('trading.gem_order_refund_grace_minutes', 5), 1);
            $canRecoverTimeoutCancellation = $currentStatus === GemTransaction::STATUS_CANCELLED
                && $gemTransaction->cancel_requested_at !== null
                && $gemTransaction->refunded_at === null
                && now()->lte($gemTransaction->cancel_requested_at->copy()->addMinutes($refundGrace));

            $validTransitions = [
                GemTransaction::STATUS_PENDING => [GemTransaction::STATUS_PROCESSING],
                GemTransaction::STATUS_PROCESSING => [GemTransaction::STATUS_COMPLETED],
            ];

            $isNormalTransition = isset($validTransitions[$currentStatus])
                && in_array($newStatus, $validTransitions[$currentStatus], true);
            $isRecoveryTransition = $canRecoverTimeoutCancellation
                && in_array($newStatus, [
                    GemTransaction::STATUS_PROCESSING,
                    GemTransaction::STATUS_COMPLETED,
                ], true);

            if (! $isNormalTransition && ! $isRecoveryTransition) {
                abort(409, "Không thể chuyển từ trạng thái '{$currentStatus}' sang '{$newStatus}'.");
            }

            $bot = null;
            $oldBotData = null;
            $newBotData = null;

            $gemTransaction->status = $newStatus;
            $gemTransaction->item = $validated['item'] ?? null;
            $gemTransaction->updated_by = 'app';
            $gemTransaction->last_synced_at = now();
            if ($isRecoveryTransition) {
                $gemTransaction->cancel_requested_at = null;
            }

            if (
                in_array($currentStatus, [
                    GemTransaction::STATUS_PROCESSING,
                    GemTransaction::STATUS_CANCELLED,
                ], true) &&
                $newStatus === GemTransaction::STATUS_COMPLETED
            ) {
                $bot = GemBot::query()
                    ->whereKey($validated['bot_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $oldBotData = $bot->historySnapshot();

                // App có thể đã sync số ngọc của bot trước khi gọi API hoàn tất đơn.
                // transactions_id là idempotency key để không trừ ngọc lần thứ hai.
                $movementKey = "gem_transaction:{$gemTransaction->id}:gem_bot:{$bot->id}";
                $balanceAlreadySynced = InventoryMovement::where('idempotency_key', $movementKey)->exists();

                $needGem = (int) $gemTransaction->gem_qty;
                $botGem = (int) $bot->gem_qty;

                if ($needGem <= 0) {
                    abort(422, 'gem_qty của giao dịch không hợp lệ.');
                }

                if (! $balanceAlreadySynced) {
                    if ($botGem < $needGem) {
                        abort(409, "Bot không đủ ngọc. Bot đang có {$botGem}, cần {$needGem}.");
                    }

                    $bot->gem_qty = $botGem - $needGem;
                    $bot->updated_by = 'app';
                    $bot->last_synced_at = now();
                    $bot->save();

                    $bot->refresh();
                    $newBotData = $bot->historySnapshot();

                    InventoryMovementService::recordGemChange(
                        bot: $bot,
                        before: $botGem,
                        after: (int) $bot->gem_qty,
                        movementType: 'sale',
                        source: 'order',
                        transactionId: $gemTransaction->id,
                        transactionType: 'gem_transaction',
                        idempotencyKey: $movementKey,
                        note: 'Gem deducted when transaction completed'
                    );
                }
            }

            $gemTransaction->save();
            $gemTransaction->refresh();

            $newTransactionData = $gemTransaction->historySnapshot();

            // log transaction history
            // TransactionHistoryService::logUpdate(
            //     transactionType: 'gem_transaction',
            //     transaction: $gemTransaction,
            //     oldData: $oldTransactionData,
            //     newData: $newTransactionData,
            //     source: 'app',
            //     action: 'status_changed',
            //     adminUserId: null,
            //     botId: $validated['bot_id'],
            //     botType: 'gem_bot',
            //     meta: [
            //         'previous_status' => $currentStatus,
            //         'new_status'      => $newStatus,
            //         'item'            => $validated['item'] ?? null,
            //         'deducted_gem'    => isset($needGem) ? $needGem : null,
            //     ],
            //     note: 'App updated gem transaction'
            // );

            // nếu có update bot thì log bot history luôn
            if ($bot && $oldBotData && $newBotData) {
                BotHistoryService::logUpdate(
                    entityType: 'gem_bot',
                    model: $bot,
                    oldData: $oldBotData,
                    newData: $newBotData,
                    source: 'order',
                    action: 'update',
                    category: 'runtime',
                    adminUserId: null,
                    transactionId: $gemTransaction->id,
                    transactionType: 'gem_transaction',
                    note: 'Gem bot updated after app completed transaction'
                );
            }

            return $gemTransaction->fresh();
        });

        $this->realtime->orderStatus(
            userId: (int) $result->user_id,
            orderType: 'gem',
            orderId: (int) $result->id,
            status: (string) $result->status,
            botId: null,
        );

        return response()->json([
            'success' => true,
            'message' => ucfirst($result->type).' updated successfully.',
            'data' => $result,
            'bot_id' => (int) $validated['bot_id'],
        ]);
    }
}
