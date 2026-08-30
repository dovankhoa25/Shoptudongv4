<?php

namespace App\Http\Controllers\AppAuto;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppAuto\AppGemTransactionResource;
use App\Models\GemTransaction;
use App\Models\Transaction;
use App\Services\UserRealtimeNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppGemTransactionController extends Controller
{
    public function __construct(private readonly UserRealtimeNotifier $realtime) {}

    public function index(Request $request)
    {
        $query = GemTransaction::query()
            ->with(['server', 'user']) // Load relationships
            ->where('updated_by', 'web')
            // ->whereIn('status', ['pending', 'processing']);
            ->whereIn('status', ['pending']);

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
            'data' => AppGemTransactionResource::collection($transactions),
        ]);
    }

    // public function update(Request $request, $id)
    // {
    //     $gemTransaction = GemTransaction::findOrFail($id);

    //     // if ($gemTransaction->updated_by !== 'web') {
    //     //     return response()->json([
    //     //         'success' => false,
    //     //         'message' => ucfirst($gemTransaction->type) . ' is already synced by app.',
    //     //     ], 403);
    //     // }

    //     $request->validate([
    //         'status' => 'required|in:processing,completed',
    //     ]);

    //     DB::transaction(function () use ($request, $gemTransaction) {
    //         $gemTransaction->lockForUpdate();

    //         $gemTransaction->status = $request->status;
    //         $gemTransaction->updated_by = 'app';
    //         $gemTransaction->last_synced_at = now();
    //         $gemTransaction->save();
    //     });

    //     return response()->json([
    //         'success' => true,
    //         'message' => ucfirst($gemTransaction->type) . ' updated successfully.',
    //         'data'    => $gemTransaction->fresh(),
    //     ]);
    // }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:processing,completed',
        ]);

        $gemTransaction = DB::transaction(function () use ($id, $validated): GemTransaction {
            $gemTransaction = GemTransaction::query()
                ->whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();
            $currentStatus = $gemTransaction->status;
            $newStatus = $validated['status'];

            if ($currentStatus === $newStatus) {
                return $gemTransaction;
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
                abort(422, "Không thể chuyển từ trạng thái '{$currentStatus}' sang '{$newStatus}'.");
            }

            $gemTransaction->status = $newStatus;
            $gemTransaction->updated_by = 'app';
            $gemTransaction->last_synced_at = now();
            if ($isRecoveryTransition) {
                $gemTransaction->cancel_requested_at = null;
            }
            $gemTransaction->save();

            return $gemTransaction->fresh();
        }, 3);

        $this->realtime->orderStatus(
            userId: (int) $gemTransaction->user_id,
            orderType: 'gem',
            orderId: (int) $gemTransaction->id,
            status: (string) $gemTransaction->status,
            botId: null,
        );

        return response()->json([
            'success' => true,
            'message' => ucfirst($gemTransaction->type).' updated successfully.',
            'data' => $gemTransaction->fresh(),
        ]);
    }
}
