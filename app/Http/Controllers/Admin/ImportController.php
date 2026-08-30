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

class ImportController extends Controller
{


    public function index(Request $request)
    {
        $query = GoldTransaction::with(['user', 'server', 'bot'])
            ->where('type', 'import');

        // Apply filters
        $this->applyFilters($query, $request);

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $orders = $query->paginate(20)->withQueryString();

        // Get filtered stats
        $stats = $this->getFilteredStats($request);

        return Inertia::render('Admin/Imports/Index', [
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
        $baseQuery = GoldTransaction::where('type', 'import');
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
                $validated['cancel_reason'] ?? null,
                'Admin cập nhật hàng loạt',
            );
        }

        return back()->with('success', 'Đã cập nhật '.count($validated['order_ids']).' đơn nhập vàng.');
    }

    /**
     * Show order detail
     */
    public function show(GoldTransaction $order)
    {
        abort_unless($order->type === GoldTransaction::TYPE_IMPORT, 404);

        $order->load(['user', 'server', 'bot']);

        return Inertia::render('Admin/Orders/Show', [
            'order' => new GoldTransactionResource($order)
        ]);
    }


    /**
     * Update order status
     */
    public function updateStatus(Request $request, GoldTransaction $order)
    {
        $validated = $request->validate([
            'status' => ['required', 'in:processing,completed,cancelled'],
            'cancel_reason' => ['required_if:status,cancelled', 'nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->transition(
            $order->id,
            $validated['status'],
            $validated['cancel_reason'] ?? null,
            $validated['note'] ?? null,
        );

        return back()->with('success', 'Trạng thái đơn nhập vàng đã được cập nhật.');
    }

    public function complete(Request $request, GoldTransaction $order)
    {
        $this->transition($order->id, GoldTransaction::STATUS_COMPLETED, null, null);

        return back()->with('success', 'Đã hoàn thành và cộng tiền cho đơn nhập vàng.');
    }

    /**
     * Cancel an order
     */
    public function cancel(Request $request, GoldTransaction $order)
    {
        $request->validate([
            'cancel_reason' => 'required|string|max:500'
        ]);

        $this->transition(
            $order->id,
            GoldTransaction::STATUS_CANCELLED,
            $request->string('cancel_reason')->toString(),
            null,
        );

        return back()->with('success', 'Đơn hàng đã được hủy thành công!');
    }

    /**
     * Process an order (move from pending to processing)
     */
    public function process(Request $request, GoldTransaction $order)
    {
        $this->transition($order->id, GoldTransaction::STATUS_PROCESSING, null, null);

        return back()->with('success', 'Đơn hàng đã chuyển sang trạng thái đang xử lý!');
    }

    private function transition(int $orderId, string $newStatus, ?string $cancelReason, ?string $note): void
    {
        DB::transaction(function () use ($orderId, $newStatus, $cancelReason, $note): void {
            $order = GoldTransaction::query()->lockForUpdate()->findOrFail($orderId);

            if ($order->type !== GoldTransaction::TYPE_IMPORT) {
                throw ValidationException::withMessages([
                    'order' => 'Giao dịch này không phải đơn nhập vàng.',
                ]);
            }

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

            $previousStatus = $order->status;
            $oldData = $order->only(['status', 'updated_by', 'last_synced_at']);

            if ($newStatus === GoldTransaction::STATUS_COMPLETED) {
                $this->creditImportOnce($order);
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
                newData: $order->only(['status', 'updated_by', 'last_synced_at']),
                source: 'web',
                action: 'gold_import.status_updated',
                adminUserId: auth()->id(),
                meta: [
                    'previous_status' => $previousStatus,
                    'new_status' => $newStatus,
                    'cancel_reason' => $cancelReason,
                    'note' => $note,
                ],
                note: 'Admin cập nhật trạng thái đơn nhập vàng',
            );
        }, 3);
    }

    private function creditImportOnce(GoldTransaction $order): void
    {
        $idempotencyKey = 'gold-import-credit:'.$order->id;

        if (Transaction::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->exists()) {
            return;
        }

        $user = User::query()->whereKey($order->user_id)->lockForUpdate()->firstOrFail();
        $amount = (int) $order->amount_vnd;
        $balanceBefore = (int) $user->balance;

        if ($balanceBefore > TransactionService::MAX_BALANCE - $amount) {
            throw ValidationException::withMessages([
                'balance' => 'Số dư sau khi nhận tiền vượt giới hạn lưu trữ.',
            ]);
        }

        $balanceAfter = $balanceBefore + $amount;
        $user->forceFill(['balance' => $balanceAfter])->save();

        Transaction::query()->create([
            'user_id' => $user->id,
            'performed_by' => auth()->id(),
            'type' => Transaction::TYPE_GOLD_IMPORT_CREDIT,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'description' => "Thanh toán đơn nhập vàng #{$order->id}",
            'related_id' => (string) $order->id,
            'related_type' => GoldTransaction::class,
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
