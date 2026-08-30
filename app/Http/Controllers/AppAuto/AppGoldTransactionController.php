<?php

namespace App\Http\Controllers\AppAuto;

use App\Http\Controllers\Controller;
use App\Models\GoldTransaction;
use App\Models\Transaction;
use App\Services\TransactionService;
use App\Services\UserRealtimeNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppGoldTransactionController extends Controller
{
    public function __construct(private readonly UserRealtimeNotifier $realtime) {}

    public function index(Request $request)
    {
        $query = GoldTransaction::query()
            ->where('updated_by', 'web')
            ->whereIn('status', ['pending', 'processing']);

        // Lọc theo type: order | import
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Lọc theo server_id nếu có
        if ($request->filled('server_id')) {
            $query->where('server_id', $request->server_id);
        }

        // Lọc theo status nếu có
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $transactions = $query->get();

        return response()->json([
            'success' => true,
            'data' => $transactions,
        ]);
    }

    // public function update(Request $request, $id)
    // {
    //     $goldTransaction = GoldTransaction::findOrFail($id);

    //     if ($goldTransaction->updated_by !== 'web') {
    //         return response()->json([
    //             'success' => false,
    //             'message' => ucfirst($goldTransaction->type) . ' is already synced by app.',
    //         ], 403);
    //     }

    //     $request->validate([
    //         'status' => 'required|in:processing,completed,cancelled',
    //     ]);

    //     $goldTransaction->update([
    //         'status'         => $request->status,
    //         'updated_by'     => 'app',
    //         'last_synced_at' => now(),
    //     ]);

    //     return response()->json([
    //         'success' => true,
    //         'message' => ucfirst($goldTransaction->type) . ' updated successfully.',
    //         'data'    => $goldTransaction->fresh(), // luôn trả bản mới nhất
    //     ]);
    // }

    // public function update(Request $request, $id)
    // {
    //     $goldTransaction = GoldTransaction::where('id', $id)->lockForUpdate()->firstOrFail();

    //     if ($goldTransaction->updated_by !== 'web') {
    //         return response()->json([
    //             'success' => false,
    //             'message' => ucfirst($goldTransaction->type) . ' is already synced by app.',
    //         ], 403);
    //     }

    //     $request->validate([
    //         'status' => 'required|in:processing,completed,cancelled',
    //     ]);

    //     DB::transaction(function () use ($request, $goldTransaction) {
    //         $goldTransaction->lockForUpdate();

    //         if ($goldTransaction->status === 'completed' && $request->status === 'completed') {
    //             throw new \Exception('Transaction already completed, cannot reprocess.');
    //         }

    //         $goldTransaction->status = $request->status;
    //         $goldTransaction->updated_by = 'app';
    //         $goldTransaction->last_synced_at = now();
    //         $goldTransaction->save();

    //         if ($goldTransaction->type === 'import' && $request->status === 'completed') {
    //             $user = $goldTransaction->user()->lockForUpdate()->firstOrFail();

    //             $amountVND = $goldTransaction->amount_vnd;

    //             if ($amountVND <= 0) {
    //                 throw new \Exception('Invalid amount_vnd in transaction.');
    //             }

    //             $user->balance += $amountVND;
    //             $user->save();

    //             TransactionService::log(
    //                 userId: $user->id,
    //                 type: 'deposit',
    //                 amount: $amountVND, // ✅ Số âm cho withdraw
    //                 description: "cộng tiền nhập vàng $user->username",
    //                 performedBy: null,
    //                 related: GoldTransaction::class
    //             );
    //         }
    //     });

    //     return response()->json([
    //         'success' => true,
    //         'message' => ucfirst($goldTransaction->type) . ' updated successfully.',
    //         'data'    => $goldTransaction->fresh(),
    //     ]);
    // }

    public function update(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:processing,completed,cancelled',
        ]);

        try {
            $updatedTransaction = DB::transaction(function () use ($request, $id) {
                // ✅ Lock thật sự bắt đầu tại đây
                $goldTransaction = GoldTransaction::where('id', $id)->lockForUpdate()->firstOrFail();

                $currentStatus = $goldTransaction->status;
                $newStatus = $request->status;

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

                $goldTransaction->status = $newStatus;
                $goldTransaction->updated_by = 'app';
                $goldTransaction->last_synced_at = now();

                if ($newStatus === GoldTransaction::STATUS_CANCELLED) {
                    $goldTransaction->cancel_requested_at ??= now();
                } elseif ($canRecoverTimedOutCancellation) {
                    $goldTransaction->cancel_requested_at = null;
                }
                $goldTransaction->save();

                if ($goldTransaction->type === 'import' && $newStatus === GoldTransaction::STATUS_COMPLETED) {
                    // ✅ Lock user tránh cộng trùng
                    $user = $goldTransaction->user()->lockForUpdate()->firstOrFail();

                    $amountVND = $goldTransaction->amount_vnd;

                    if ($amountVND <= 0) {
                        throw new \Exception('Invalid amount_vnd in transaction.');
                    }

                    // ✅ Double-safe: chỉ cộng nếu chưa có log
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
                        description: "Cộng tiền nhập vàng $user->username",
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
                'message' => 'Transaction updated successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
