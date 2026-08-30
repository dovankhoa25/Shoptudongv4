<?php

namespace App\Http\Controllers\AppAuto\VersionTwo;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppAuto\VersionTwo\AppGoldTransactionVersionTwoResource;
use App\Models\Bot;
use App\Models\GoldTransaction;
use App\Models\InventoryMovement;
use App\Models\Transaction;
use App\Services\BotHistoryService;
use App\Services\InventoryMovementService;
use App\Services\TransactionService;
use App\Services\UserRealtimeNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppGoldTransactionVersionTwoController extends Controller
{
    public function __construct(private readonly UserRealtimeNotifier $realtime) {}

    public function index(Request $request)
    {
        $query = GoldTransaction::query()
            ->where('updated_by', 'web')
            ->whereIn('status', ['pending', 'processing']);

        // Lọc theo type: order | import
        if ($request->filled('type')) {
            $query->where('type', $request->string('type')->toString());
        }

        // Lọc theo server_id nếu có
        if ($request->filled('server_id')) {
            $query->where('server_id', (int) $request->input('server_id'));
        }

        // Lọc theo status nếu có (lưu ý: hiện query đang giới hạn pending/processing)
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        // Sắp xếp (tuỳ bạn)
        $query->latest('id');

        // $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        // $transactions = $query->paginate($perPage);
        $transactions = $query->get();

        return AppGoldTransactionVersionTwoResource::collection($transactions)
            ->additional([
                'success' => true,
            ]);
    }

    public function store(Request $request)
    {

        $request->validate([
            'type' => 'nullable|string|max:255',
            'server_id' => 'nullable|string|max:255',
            'server_game_id' => 'nullable|string|max:255',
            'character_name' => 'nullable|string|max:255',
            'gold_qty' => 'nullable|string|max:255',
            'gold_bar_qty' => 'nullable|string|max:255',

        ]);
        // Tạo gold transaction
        $order = GoldTransaction::create([
            'type' => $request->type,
            'user_id' => 1,
            'server_id' => $request->server_id,
            'server_game_id' => $request->server_id,
            'character_name' => $request->character_name,
            'gold_qty' => $request->gold_qty,
            'gold_bar_qty' => $request->gold_bar_qty,
            'status' => 'pending',
            'bot_id' => null,
            'updated_by' => 'web',
            'price_at_transaction' => 10000,
        ]);

        return response()->json([
            'success' => true,
            'data' => 'Transaction updated successfully.',
        ]);
    }

    // public function update(Request $request, $id)
    // {
    //     $request->validate([
    //         'status' => 'required|in:processing,completed,cancelled',
    //         'bot_id' => 'nullable|integer',
    //         'gold_bar_qty' => 'required',
    //         'gold_qty' => 'required',
    //     ]);

    //     try {
    //         DB::transaction(function () use ($request, $id) {
    //             // ✅ Lock thật sự bắt đầu tại đây
    //             $goldTransaction = GoldTransaction::where('id', $id)->lockForUpdate()->firstOrFail();

    //             if ($goldTransaction->updated_by !== 'web') {
    //                 throw new \Exception(ucfirst($goldTransaction->type) . ' is already synced by app.');
    //             }

    //             if ($goldTransaction->status === 'completed' && $request->status === 'completed') {
    //                 throw new \Exception('Transaction already completed.');
    //             }

    //             $goldTransaction->status = $request->status;
    //             $goldTransaction->updated_by = 'app';
    //             $goldTransaction->last_synced_at = now();
    //             $goldTransaction->save();

    //             if ($goldTransaction->type === 'import' && $request->status === 'completed') {
    //                 // ✅ Lock user tránh cộng trùng
    //                 $user = $goldTransaction->user()->lockForUpdate()->firstOrFail();

    //                 $amountVND = $goldTransaction->amount_vnd;

    //                 if ($amountVND <= 0) {
    //                     throw new \Exception('Invalid amount_vnd in transaction.');
    //                 }

    //                 // ✅ Double-safe: chỉ cộng nếu chưa có log
    //                 $alreadyLogged = Transaction::where([
    //                     'related_id'   => $goldTransaction->id,
    //                     'related_type' => GoldTransaction::class,
    //                     'type'         => 'Nhập Vàng',
    //                 ])->exists();

    //                 if ($alreadyLogged) {
    //                     throw new \Exception('Already credited this transaction.');
    //                 }

    //                 $user->balance += $amountVND;
    //                 $user->save();

    //                 TransactionService::log(
    //                     userId: $user->id,
    //                     type: 'Nhập Vàng',
    //                     amount: $amountVND,
    //                     description: "Cộng tiền nhập vàng $user->username",
    //                     performedBy: null,
    //                     related: $goldTransaction
    //                 );
    //             }
    //         });

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Transaction updated successfully.',
    //             'bot_id' => $request->bot_id,
    //             'gold_qty' => $request->gold_qty,
    //             'gold_bar_qty' => $request->gold_bar_qty,
    //         ]);
    //     } catch (\Throwable $e) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => $e->getMessage(),
    //         ], 422);
    //     }
    // }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:processing,completed,cancelled',
            'bot_id' => 'required|integer|min:1',
            'gold_bar_qty' => 'required|integer|min:0',
            'gold_qty' => 'required|integer|min:0',
        ]);

        try {
            $updatedTransaction = DB::transaction(function () use ($validated, $id) {

                // 1) Lock transaction
                $goldTransaction = GoldTransaction::query()
                    ->whereKey($id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $currentStatus = $goldTransaction->status;
                $newStatus = $validated['status'];

                // Retry cùng trạng thái là idempotent. updated_by chỉ ghi nguồn cập nhật cuối,
                // không được dùng làm khóa vì app cần gọi processing rồi completed.
                if ($currentStatus === $newStatus) {
                    return $goldTransaction;
                }

                $refundGrace = max((int) config('trading.gold_order_refund_grace_minutes', 5), 1);
                $canRecoverTimedOutCancellation = $currentStatus === GoldTransaction::STATUS_CANCELLED
                    && $goldTransaction->cancel_requested_at !== null
                    && $goldTransaction->refunded_at === null
                    && now()->lte($goldTransaction->cancel_requested_at->copy()->addMinutes($refundGrace));

                $validTransitions = [
                    GoldTransaction::STATUS_PENDING => [
                        GoldTransaction::STATUS_PROCESSING,
                        GoldTransaction::STATUS_COMPLETED,
                        GoldTransaction::STATUS_CANCELLED,
                    ],
                    GoldTransaction::STATUS_PROCESSING => [
                        GoldTransaction::STATUS_COMPLETED,
                        GoldTransaction::STATUS_CANCELLED,
                    ],
                    GoldTransaction::STATUS_CANCELLED => $canRecoverTimedOutCancellation
                        ? [GoldTransaction::STATUS_PROCESSING, GoldTransaction::STATUS_COMPLETED]
                        : [],
                ];

                if (! in_array($newStatus, $validTransitions[$currentStatus] ?? [], true)) {
                    abort(409, "Không thể chuyển từ '{$currentStatus}' sang '{$newStatus}'.");
                }

                // 3) Update transaction trước
                $goldTransaction->status = $newStatus;
                $goldTransaction->updated_by = 'app';
                $goldTransaction->last_synced_at = now();

                if ($newStatus === GoldTransaction::STATUS_CANCELLED) {
                    $goldTransaction->cancel_requested_at ??= now();
                } elseif ($canRecoverTimedOutCancellation) {
                    $goldTransaction->cancel_requested_at = null;
                }

                // Nếu bạn có cột bot_id trong gold_transactions thì lưu luôn:
                // $goldTransaction->bot_id = $validated['bot_id'] ?? null;

                $goldTransaction->save();

                // 4) Nếu completed và có bot_id => update bot
                if ($newStatus === 'completed') {
                    if (empty($validated['bot_id'])) {
                        abort(422, 'Thiếu bot_id khi completed.');
                    }

                    // Lock bot
                    $bot = Bot::query()
                        ->whereKey($validated['bot_id'])   // = where('id', bot_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $movementKey = "gold_transaction:{$goldTransaction->id}:bot:{$bot->id}";
                    $balanceAlreadySynced = InventoryMovement::where('idempotency_key', $movementKey)->exists();

                    $oldBotData = $bot->only(['gold_qty', 'gold_bar_qty']);

                    // 4a) OPTION A: SET lại số bot theo số app gửi lên (đồng bộ tuyệt đối)
                    if (! $balanceAlreadySynced) {
                        $bot->gold_qty = (int) $validated['gold_qty'];
                        $bot->gold_bar_qty = (int) $validated['gold_bar_qty'];

                        // 4b) OPTION B: TRỪ theo giao dịch (nếu goldTransaction có qty cần trừ)
                        // $needGold     = (int) $goldTransaction->gold_qty;
                        // $needGoldBar  = (int) $goldTransaction->gold_bar_qty;
                        // if ($bot->gold_qty < $needGold || $bot->gold_bar_qty < $needGoldBar) abort(409,'Bot không đủ vàng.');
                        // $bot->gold_qty     -= $needGold;
                        // $bot->gold_bar_qty -= $needGoldBar;

                        $bot->updated_by = 'app';
                        $bot->save();

                        $newBotData = $bot->fresh()->only(['gold_qty', 'gold_bar_qty']);

                        InventoryMovementService::recordGoldChange(
                            bot: $bot,
                            beforeGold: (int) $oldBotData['gold_qty'],
                            beforeBars: (int) $oldBotData['gold_bar_qty'],
                            afterGold: (int) $newBotData['gold_qty'],
                            afterBars: (int) $newBotData['gold_bar_qty'],
                            movementType: $goldTransaction->type === 'import' ? 'import' : 'sale',
                            source: 'order',
                            transactionId: $goldTransaction->id,
                            transactionType: 'gold_transaction',
                            idempotencyKey: $movementKey,
                            note: 'Converted gold updated when transaction completed'
                        );

                        BotHistoryService::logUpdate(
                            entityType: 'bot',
                            model: $bot,
                            oldData: $oldBotData,
                            newData: $newBotData,
                            source: 'order',
                            action: 'update',
                            category: 'runtime',
                            adminUserId: null,
                            transactionId: $goldTransaction->id,
                            transactionType: 'gold_transaction',
                            note: 'Gold bot updated when app completed transaction'
                        );
                    }
                }

                // 5) Logic cộng tiền user cho import (giữ nguyên của bạn), nhưng chỉ chạy khi completed
                if ($goldTransaction->type === 'import' && $newStatus === 'completed') {

                    $user = $goldTransaction->user()->lockForUpdate()->firstOrFail();
                    $amountVND = (int) $goldTransaction->amount_vnd;

                    if ($amountVND <= 0) {
                        throw new \Exception('Invalid amount_vnd in transaction.');
                    }

                    $alreadyLogged = Transaction::where([
                        'related_id' => $goldTransaction->id,
                        'related_type' => GoldTransaction::class,
                        'type' => 'Nhập Vàng',
                    ])->exists();

                    if ($alreadyLogged) {
                        throw new \Exception('Already credited this transaction.');
                    }

                    $balanceBefore = (int) $user->balance;
                    $user->balance += $amountVND;
                    $user->save();
                    $balanceAfter = (int) $user->balance;

                    TransactionService::log(
                        userId: $user->id,
                        type: 'Nhập Vàng',
                        amount: $amountVND,
                        description: "Cộng tiền nhập vàng {$user->username}",
                        performedBy: null,
                        related: $goldTransaction,
                        relatedId: $goldTransaction->id,
                        oldBalance: $balanceBefore,
                        newBalance: $balanceAfter,
                        idempotencyKey: "gold-import-credit:{$goldTransaction->id}",
                        metadata: [
                            'source' => 'app',
                            'gold_transaction_id' => $goldTransaction->id,
                            'status' => $goldTransaction->status,
                        ],
                    );
                }

                return $goldTransaction->fresh();
            });

            $this->realtime->orderStatus(
                userId: (int) $updatedTransaction->user_id,
                orderType: 'gold',
                orderId: (int) $updatedTransaction->id,
                status: (string) $updatedTransaction->status,
                botId: null,
            );

            return response()->json([
                'success' => true,
                'message' => 'Transaction updated successfully.'.$id,
                'bot_id' => $validated['bot_id'] ?? null,
                'gold_qty' => (int) $validated['gold_qty'],
                'gold_bar_qty' => (int) $validated['gold_bar_qty'],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
