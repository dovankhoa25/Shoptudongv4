<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\GoldTransaction\GoldTransactionResource;
use App\Models\Bot;
use App\Models\GoldTransaction;
use App\Models\Server;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionHistoryService;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OrderController extends Controller
{
    // public function index(Request $request)
    // {
    //     $query = GoldTransaction::with(['user', 'server', 'bot'])
    //         ->where('type', 'order'); // Filter chỉ lấy đơn bán vàng

    //     // Filter by server
    //     if ($request->filled('server_id')) {
    //         $query->where('server_id', $request->server_id);
    //     }

    //     // Filter by bot
    //     if ($request->filled('bot_id')) {
    //         $query->where('bot_id', $request->bot_id);
    //     }

    //     // Filter by status
    //     if ($request->filled('status')) {
    //         $query->where('status', $request->status);
    //     }

    //     // Filter by date range
    //     if ($request->filled('date_from')) {
    //         $query->whereDate('created_at', '>=', $request->date_from);
    //     }

    //     if ($request->filled('date_to')) {
    //         $query->whereDate('created_at', '<=', $request->date_to);
    //     }

    //     // Search by character name or user
    //     if ($request->filled('search')) {
    //         $query->where(function ($q) use ($request) {
    //             $q->where('character_name', 'LIKE', '%' . $request->search . '%')
    //                 ->orWhereHas('user', function ($userQuery) use ($request) {
    //                     $userQuery->where('username', 'LIKE', '%' . $request->search . '%')
    //                         ->orWhere('email', 'LIKE', '%' . $request->search . '%');
    //                 });
    //         });
    //     }

    //     // Sort
    //     $sortBy = $request->get('sort_by', 'created_at');
    //     $sortOrder = $request->get('sort_order', 'desc');
    //     $query->orderBy($sortBy, $sortOrder);

    //     $orders = $query->paginate(20)->withQueryString();

    //     // Get statistics
    //     $stats = [
    //         'total_orders' => GoldTransaction::where('type', 'order')->count(),
    //         'pending_orders' => GoldTransaction::where('type', 'order')->where('status', 'pending')->count(),
    //         'processing_orders' => GoldTransaction::where('type', 'order')->where('status', 'processing')->count(),
    //         'completed_orders' => GoldTransaction::where('type', 'order')->where('status', 'completed')->count(),
    //         'cancelled_orders' => GoldTransaction::where('type', 'order')->where('status', 'cancelled')->count(),
    //         'failed_orders' => GoldTransaction::where('type', 'order')->where('status', 'failed')->count(),
    //         'total_revenue' => GoldTransaction::where('type', 'order')->where('status', 'completed')->sum('amount_vnd'),
    //         'total_gold_sold' => GoldTransaction::where('type', 'order')->where('status', 'completed')->sum('gold_qty'),
    //         'today_orders' => GoldTransaction::where('type', 'order')->whereDate('created_at', today())->count(),
    //         'today_revenue' => GoldTransaction::where('type', 'order')
    //             ->where('status', 'completed')
    //             ->whereDate('created_at', today())
    //             ->sum('amount_vnd'),
    //     ];

    //     return Inertia::render('Admin/Orders/Index', [
    //         'orders' => GoldTransactionResource::collection($orders),
    //         'servers' => Server::active()->get(['id', 'name']),
    //         'bots' => Bot::active()->get(['id', 'name']),
    //         'filters' => $request->only(['server_id', 'bot_id', 'status', 'search', 'date_from', 'date_to', 'sort_by', 'sort_order']),
    //         'stats' => $stats
    //     ]);
    // }



    public function index(Request $request)
    {
        $query = GoldTransaction::with(['user', 'server', 'bot'])
            ->where('type', 'order');

        // Apply filters
        $this->applyFilters($query, $request);

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $orders = $query->paginate(20)->withQueryString();

        // Get filtered stats
        $stats = $this->getFilteredStats($request);

        return Inertia::render('Admin/Orders/Index', [
            'orders' => GoldTransactionResource::collection($orders),
            'servers' => Server::active()->get(['id', 'name']),
            'bots' => Bot::active()->get(['id', 'name']),
            'filters' => $request->only(['server_id', 'bot_id', 'status', 'search', 'date_from', 'date_to', 'sort_by', 'sort_order']),
            'stats' => $stats
        ]);
    }

    /**
     * Apply filters to query
     */
    private function applyFilters($query, Request $request)
    {
        // Filter by server
        if ($request->filled('server_id')) {
            $query->where('server_id', $request->server_id);
        }

        // Filter by bot
        if ($request->filled('bot_id')) {
            $query->where('bot_id', $request->bot_id);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Search by character name or user
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('character_name', 'LIKE', '%' . $request->search . '%')
                    ->orWhereHas('user', function ($userQuery) use ($request) {
                        $userQuery->where('username', 'LIKE', '%' . $request->search . '%')
                            ->orWhere('email', 'LIKE', '%' . $request->search . '%');
                    });
            });
        }

        return $query;
    }

    /**
     * Get statistics with filters applied
     */
    private function getFilteredStats(Request $request)
    {
        $baseQuery = GoldTransaction::where('type', 'order');
        $this->applyFilters($baseQuery, $request);

        return [
            'total_orders' => (clone $baseQuery)->count(),
            'pending_orders' => (clone $baseQuery)->where('status', 'pending')->count(),
            'processing_orders' => (clone $baseQuery)->where('status', 'processing')->count(),
            'completed_orders' => (clone $baseQuery)->where('status', 'completed')->count(),
            'cancelled_orders' => (clone $baseQuery)->where('status', 'cancelled')->count(),
            'failed_orders' => 0,
            'total_revenue' => (clone $baseQuery)->where('status', 'completed')->sum('amount_vnd'),
            'total_gold_sold' => (clone $baseQuery)->where('status', 'completed')->sum('gold_qty'),
            'today_orders' => (clone $baseQuery)->whereDate('created_at', today())->count(),
            'today_revenue' => (clone $baseQuery)
                ->where('status', 'completed')
                ->whereDate('created_at', today())
                ->sum('amount_vnd'),
        ];
    }

    /**
     * Bulk update status for multiple orders
     */
    // public function bulkUpdateStatus(Request $request)
    // {
    //     $request->validate([
    //         'order_ids' => 'required|array',
    //         'order_ids.*' => 'exists:gold_transactions,id',
    //         'status' => 'required|in:pending,processing,completed,cancelled,failed'
    //     ]);

    //     $updated = GoldTransaction::whereIn('id', $request->order_ids)
    //         ->where('type', 'order')
    //         ->update([
    //             'status' => $request->status,
    //             'updated_by' => auth()->id(),
    //             'updated_at' => now()
    //         ]);

    //     return back()->with('success', "Đã cập nhật trạng thái cho {$updated} đơn hàng!");
    // }

    /**
     * Show order detail
     */
    public function show(GoldTransaction $order)
    {
        abort_unless($order->type === GoldTransaction::TYPE_ORDER, 404);

        $order->load(['user', 'server', 'bot']);

        return Inertia::render('Admin/Orders/Show', [
            'order' => new GoldTransactionResource($order)
        ]);
    }


    /**
     * Update order status (unified endpoint)
     */
    public function updateStatus(Request $request, GoldTransaction $order)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:processing,completed,cancelled'],
            'cancel_reason' => ['required_if:status,cancelled', 'nullable', 'string', 'max:500'],
            'refund_amount' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->transition(
            $order->id,
            $validated['status'],
            $validated['cancel_reason'] ?? null,
            $validated['note'] ?? null,
            isset($validated['refund_amount']) ? (int) $validated['refund_amount'] : null,
        );

        return back()->with('success', 'Trạng thái đơn hàng đã được cập nhật!');
    }

    public function bulkUpdateStatus(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['integer', 'distinct', 'exists:gold_transactions,id'],
            'status' => ['required', 'in:processing,completed,cancelled'],
            'cancel_reason' => ['required_if:status,cancelled', 'nullable', 'string', 'max:500'],
        ]);

        foreach ($validated['order_ids'] as $orderId) {
            $this->transition(
                (int) $orderId,
                $validated['status'],
                $validated['cancel_reason'] ?? 'Admin hủy hàng loạt',
                'Admin cập nhật hàng loạt',
            );
        }

        return back()->with('success', 'Đã cập nhật '.count($validated['order_ids']).' đơn mua vàng.');
    }

    private function transition(
        int $orderId,
        string $newStatus,
        ?string $cancelReason,
        ?string $note,
        ?int $refundAmount = null,
    ): void
    {
        DB::transaction(function () use ($orderId, $newStatus, $cancelReason, $note, $refundAmount): void {
            $order = GoldTransaction::query()->lockForUpdate()->findOrFail($orderId);
            abort_unless($order->type === GoldTransaction::TYPE_ORDER, 404);

            $allowedTransitions = [
                GoldTransaction::STATUS_PENDING => [GoldTransaction::STATUS_PROCESSING, GoldTransaction::STATUS_CANCELLED],
                GoldTransaction::STATUS_PROCESSING => [GoldTransaction::STATUS_COMPLETED, GoldTransaction::STATUS_CANCELLED],
                GoldTransaction::STATUS_COMPLETED => [],
                GoldTransaction::STATUS_CANCELLED => [],
            ];

            if (! in_array($newStatus, $allowedTransitions[$order->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => "Không thể chuyển từ [{$order->status}] sang [{$newStatus}].",
                ]);
            }

            $oldData = $order->only(['status', 'updated_by', 'last_synced_at', 'refunded_at']);
            $previousStatus = $order->status;

            if ($newStatus === GoldTransaction::STATUS_CANCELLED) {
                $this->refundOnce($order, $cancelReason ?? 'Admin hủy đơn', $refundAmount);
            }

            $order->update([
                'status' => $newStatus,
                'updated_by' => 'web',
                'last_synced_at' => now(),
            ]);
            $order->refresh();

            TransactionHistoryService::logUpdate(
                transactionType: 'gold_transaction',
                transaction: $order,
                oldData: $oldData,
                newData: $order->only(['status', 'updated_by', 'last_synced_at', 'refunded_at']),
                source: 'web',
                action: 'gold_order.status_updated',
                adminUserId: auth()->id(),
                meta: [
                    'previous_status' => $previousStatus,
                    'new_status' => $newStatus,
                    'cancel_reason' => $cancelReason,
                    'refund_amount' => $newStatus === GoldTransaction::STATUS_CANCELLED
                        ? ($refundAmount ?? (int) $order->amount_vnd)
                        : null,
                    'note' => $note,
                ],
                note: 'Admin cập nhật trạng thái đơn mua vàng',
            );
        }, 3);
    }

    private function refundOnce(GoldTransaction $order, string $reason, ?int $requestedAmount = null): void
    {
        $idempotencyKey = 'gold-order-refund:'.$order->id;

        if (Transaction::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->exists()) {
            if ($order->refunded_at === null) {
                $order->forceFill(['refunded_at' => now()])->save();
            }

            return;
        }

        $user = User::query()->whereKey($order->user_id)->lockForUpdate()->firstOrFail();
        $orderAmount = (int) $order->amount_vnd;
        $amount = $requestedAmount ?? $orderAmount;

        if ($orderAmount <= 0 || $amount > $orderAmount) {
            throw ValidationException::withMessages([
                'refund_amount' => 'Số tiền hoàn không hợp lệ hoặc vượt quá giá trị đơn.',
            ]);
        }

        $balanceBefore = (int) $user->balance;

        if ($balanceBefore > TransactionService::MAX_BALANCE - $amount) {
            throw ValidationException::withMessages([
                'balance' => 'Số dư sau hoàn tiền vượt giới hạn lưu trữ.',
            ]);
        }

        $balanceAfter = $balanceBefore + $amount;
        $user->forceFill(['balance' => $balanceAfter])->save();
        $order->forceFill(['refunded_at' => now()])->save();

        Transaction::query()->create([
            'user_id' => $user->id,
            'performed_by' => auth()->id(),
            'type' => Transaction::TYPE_GOLD_ORDER_REFUND,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'description' => "Hoàn tiền đơn mua vàng #{$order->id}: {$reason}",
            'related_id' => (string) $order->id,
            'related_type' => GoldTransaction::class,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
