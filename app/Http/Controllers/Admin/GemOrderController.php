<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\GemOrder\GemOrderResource;
use App\Models\GemTransaction;
use App\Models\Server;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionHistoryService;
use App\Services\TransactionService;
use App\Services\UserRealtimeNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GemOrderController extends Controller
{
    private const STATUSES = [
        GemTransaction::STATUS_PENDING,
        GemTransaction::STATUS_PROCESSING,
        GemTransaction::STATUS_COMPLETED,
        GemTransaction::STATUS_CANCELLED,
        GemTransaction::STATUS_REFUNDED,
    ];

    private const SORTABLE_COLUMNS = [
        'id',
        'amount_vnd',
        'gem_qty',
        'status',
        'created_at',
        'updated_at',
    ];

    public function __construct(private readonly UserRealtimeNotifier $realtime) {}

    public function index(Request $request)
    {
        $query = GemTransaction::query()->with(['user', 'server']);
        $this->applyFilters($query, $request);
        $this->applySorting($query, $request);

        $perPage = (int) $request->integer('per_page', 20);
        if (! in_array($perPage, [10, 20, 50, 100], true)) {
            $perPage = 20;
        }

        return Inertia::render('Admin/GemOrders/Index', [
            'orders' => GemOrderResource::collection($query->paginate($perPage)->withQueryString()),
            'servers' => Server::query()->active()->get(['id', 'name']),
            'filters' => $request->only([
                'server_id',
                'status',
                'search',
                'date_from',
                'date_to',
                'sort_by',
                'sort_order',
            ]),
            'stats' => $this->filteredStats($request),
        ]);
    }

    public function show(GemTransaction $order)
    {
        $order->load(['user', 'server']);

        $relatedOrders = GemTransaction::query()
            ->with(['user', 'server'])
            ->where('user_id', $order->user_id)
            ->whereKeyNot($order->id)
            ->latest()
            ->limit(5)
            ->get();

        return Inertia::render('Admin/GemOrders/Show', [
            'order' => new GemOrderResource($order),
            'relatedOrders' => GemOrderResource::collection($relatedOrders),
        ]);
    }

    public function updateStatus(Request $request, GemTransaction $order)
    {
        $validated = $request->validate([
            'action' => ['required', 'in:complete,cancel,set_status'],
            'status' => ['nullable', 'required_if:action,set_status', 'in:pending,processing,completed,cancelled'],
            'note' => ['nullable', 'string', 'max:500'],
            'cancel_reason' => [
                'nullable',
                'string',
                'max:500',
                'required_if:action,cancel',
                'required_if:status,cancelled',
            ],
        ]);

        $targetStatus = match ($validated['action']) {
            'complete' => GemTransaction::STATUS_COMPLETED,
            'cancel' => GemTransaction::STATUS_CANCELLED,
            'set_status' => $validated['status'],
        };

        $updatedOrder = $this->transition(
            orderId: (int) $order->id,
            targetStatus: $targetStatus,
            cancelReason: $validated['cancel_reason'] ?? null,
            note: $validated['note'] ?? null,
            action: $validated['action'],
        );

        $this->notifyStatus($updatedOrder);

        return Redirect::back()->with('success', 'Trạng thái đơn ngọc đã được cập nhật.');
    }

    public function refund(Request $request, GemTransaction $order)
    {
        $validated = $request->validate([
            'refund_reason' => ['required', 'string', 'max:500'],
            'refund_amount' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = DB::transaction(function () use ($order, $validated): array {
            $lockedOrder = GemTransaction::query()->lockForUpdate()->findOrFail($order->id);

            if (! in_array($lockedOrder->status, [
                GemTransaction::STATUS_PENDING,
                GemTransaction::STATUS_PROCESSING,
            ], true) || $lockedOrder->refunded_at !== null) {
                throw ValidationException::withMessages([
                    'status' => 'Chỉ có thể hoàn tiền cho đơn ngọc đang chờ hoặc đang xử lý.',
                ]);
            }

            $orderAmount = (int) $lockedOrder->amount_vnd;
            $refundAmount = (int) ($validated['refund_amount'] ?? $orderAmount);

            if ($orderAmount <= 0 || $refundAmount > $orderAmount) {
                throw ValidationException::withMessages([
                    'refund_amount' => 'Số tiền hoàn không hợp lệ hoặc vượt quá giá trị đơn.',
                ]);
            }

            $oldData = $lockedOrder->historySnapshot();
            $balances = $this->creditRefundOnce(
                order: $lockedOrder,
                amount: $refundAmount,
                reason: $validated['refund_reason'],
                idempotencyKey: 'gem-order-refund:'.$lockedOrder->id,
            );

            $lockedOrder->forceFill([
                'status' => GemTransaction::STATUS_REFUNDED,
                'updated_by' => 'web',
                'last_synced_at' => now(),
                'refunded_at' => now(),
            ])->save();
            $lockedOrder->refresh();

            TransactionHistoryService::logUpdate(
                transactionType: 'gem_transaction',
                transaction: $lockedOrder,
                oldData: $oldData,
                newData: $lockedOrder->historySnapshot(),
                source: 'web',
                action: 'gem_order.refund',
                adminUserId: auth()->id(),
                meta: [
                    'refund_reason' => $validated['refund_reason'],
                    'refund_amount' => $refundAmount,
                    'balance_before' => $balances['before'],
                    'balance_after' => $balances['after'],
                ],
                note: 'Admin hoàn tiền đơn mua ngọc',
            );

            return [
                'order' => $lockedOrder,
                'amount' => $refundAmount,
                'balance' => $balances['after'],
            ];
        }, 3);

        $this->notifyStatus($result['order']);
        $this->realtime->balanceChanged(
            userId: (int) $result['order']->user_id,
            amount: (int) $result['amount'],
            balance: (int) $result['balance'],
            message: "Admin đã hoàn tiền đơn ngọc #{$result['order']->id}.",
        );

        return Redirect::back()->with('success', 'Đã hoàn tiền '.number_format($result['amount']).' VND.');
    }

    public function bulkUpdateStatus(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['integer', 'distinct', 'exists:gem_transactions,id'],
            'status' => ['required', 'in:processing,completed,cancelled'],
            'cancel_reason' => [
                'nullable',
                'string',
                'max:500',
                'required_if:status,cancelled',
            ],
        ]);

        $orderIds = collect($validated['order_ids'])->map(fn ($id): int => (int) $id)->sort()->values();
        $updatedOrders = DB::transaction(function () use ($orderIds, $validated): array {
            $orders = GemTransaction::query()
                ->whereKey($orderIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($orders as $order) {
                if (! $this->canTransitionStatus($order, $validated['status'])) {
                    throw ValidationException::withMessages([
                        'status' => "Đơn #{$order->id} không thể chuyển từ {$order->status} sang {$validated['status']}.",
                    ]);
                }
            }

            return $orders->map(fn (GemTransaction $order): GemTransaction => $this->applyTransition(
                order: $order,
                targetStatus: $validated['status'],
                cancelReason: $validated['cancel_reason'] ?? null,
                note: 'Admin cập nhật hàng loạt',
                action: 'set_status',
            ))->all();
        }, 3);

        foreach ($updatedOrders as $updatedOrder) {
            $this->notifyStatus($updatedOrder);
        }

        return Redirect::back()->with('success', 'Đã cập nhật '.count($updatedOrders).' đơn ngọc.');
    }

    public function export(Request $request): StreamedResponse
    {
        $query = GemTransaction::query()->with(['user', 'server']);
        $this->applyFilters($query, $request);
        $this->applySorting($query, $request);

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Mã đơn',
                'Khách hàng',
                'Email',
                'Server',
                'Nhân vật',
                'Số tiền',
                'Số ngọc',
                'Trạng thái',
                'Nguồn cập nhật',
                'Ngày tạo',
                'Ngày hoàn tiền',
            ]);

            $query->chunkById(500, function ($orders) use ($output): void {
                foreach ($orders as $order) {
                    fputcsv($output, [
                        $order->id,
                        $order->user?->username,
                        $order->user?->email,
                        $order->server?->name,
                        $order->character_name,
                        (int) $order->amount_vnd,
                        (int) $order->gem_qty,
                        $order->status,
                        $order->updated_by,
                        $order->created_at?->format('Y-m-d H:i:s'),
                        $order->refunded_at?->format('Y-m-d H:i:s'),
                    ]);
                }
            });

            fclose($output);
        }, 'gem-orders-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function statistics(Request $request)
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:today,week,month,year'],
        ]);

        $query = GemTransaction::query();
        match ($validated['period'] ?? 'today') {
            'week' => $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]),
            'month' => $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]),
            'year' => $query->whereBetween('created_at', [now()->startOfYear(), now()->endOfYear()]),
            default => $query->whereDate('created_at', today()),
        };

        return response()->json([
            'total_orders' => (clone $query)->count(),
            'total_revenue' => (clone $query)->where('status', GemTransaction::STATUS_COMPLETED)->sum('amount_vnd'),
            'total_gems' => (clone $query)->where('status', GemTransaction::STATUS_COMPLETED)->sum('gem_qty'),
            'average_order_value' => (clone $query)->where('status', GemTransaction::STATUS_COMPLETED)->avg('amount_vnd'),
            'by_status' => (clone $query)
                ->selectRaw('status, count(*) as count, sum(amount_vnd) as total')
                ->groupBy('status')
                ->get(),
            'by_server' => (clone $query)
                ->join('servers', 'gem_transactions.server_id', '=', 'servers.id')
                ->selectRaw('server_id, servers.name as server_name, count(*) as count, sum(amount_vnd) as total')
                ->groupBy('server_id', 'servers.name')
                ->get(),
        ]);
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('server_id')) {
            $query->where('server_id', $request->integer('server_id'));
        }

        if ($request->filled('status') && in_array($request->string('status')->toString(), self::STATUSES, true)) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('character_name', 'like', "%{$search}%")
                    ->orWhereKey(is_numeric($search) ? (int) $search : 0)
                    ->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                        $userQuery->where('username', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }
    }

    private function applySorting(Builder $query, Request $request): void
    {
        $sortBy = $request->string('sort_by', 'created_at')->toString();
        $sortOrder = $request->string('sort_order', 'desc')->lower()->toString();

        if (! in_array($sortBy, self::SORTABLE_COLUMNS, true)) {
            $sortBy = 'created_at';
        }

        $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
    }

    /** @return array<string, int|float|string|null> */
    private function filteredStats(Request $request): array
    {
        $baseQuery = GemTransaction::query();
        $this->applyFilters($baseQuery, $request);

        return [
            'total_orders' => (clone $baseQuery)->count(),
            'pending_orders' => (clone $baseQuery)->where('status', GemTransaction::STATUS_PENDING)->count(),
            'processing_orders' => (clone $baseQuery)->where('status', GemTransaction::STATUS_PROCESSING)->count(),
            'completed_orders' => (clone $baseQuery)->where('status', GemTransaction::STATUS_COMPLETED)->count(),
            'cancelled_orders' => (clone $baseQuery)->where('status', GemTransaction::STATUS_CANCELLED)->count(),
            'refunded_orders' => (clone $baseQuery)->where('status', GemTransaction::STATUS_REFUNDED)->count(),
            'total_revenue' => (clone $baseQuery)->where('status', GemTransaction::STATUS_COMPLETED)->sum('amount_vnd'),
            'total_gems_sold' => (clone $baseQuery)->where('status', GemTransaction::STATUS_COMPLETED)->sum('gem_qty'),
            'today_orders' => (clone $baseQuery)->whereDate('created_at', today())->count(),
            'today_revenue' => (clone $baseQuery)
                ->where('status', GemTransaction::STATUS_COMPLETED)
                ->whereDate('created_at', today())
                ->sum('amount_vnd'),
        ];
    }

    private function transition(
        int $orderId,
        string $targetStatus,
        ?string $cancelReason,
        ?string $note,
        string $action,
    ): GemTransaction {
        return DB::transaction(function () use ($orderId, $targetStatus, $cancelReason, $note, $action): GemTransaction {
            $order = GemTransaction::query()->lockForUpdate()->findOrFail($orderId);

            return $this->applyTransition($order, $targetStatus, $cancelReason, $note, $action);
        }, 3);
    }

    private function applyTransition(
        GemTransaction $order,
        string $targetStatus,
        ?string $cancelReason,
        ?string $note,
        string $action,
    ): GemTransaction {
        if (! $this->canTransitionStatus($order, $targetStatus)) {
            throw ValidationException::withMessages([
                'status' => "Không thể chuyển trạng thái từ {$order->status} sang {$targetStatus}.",
            ]);
        }

        $oldData = $order->historySnapshot();
        $previousStatus = $order->status;
        $order->forceFill([
            'status' => $targetStatus,
            'updated_by' => 'web',
            'last_synced_at' => now(),
            'cancel_requested_at' => null,
        ])->save();
        $order->refresh();

        TransactionHistoryService::logUpdate(
            transactionType: 'gem_transaction',
            transaction: $order,
            oldData: $oldData,
            newData: $order->historySnapshot(),
            source: 'web',
            action: "gem_order.{$action}",
            adminUserId: auth()->id(),
            meta: array_filter([
                'previous_status' => $previousStatus,
                'new_status' => $targetStatus,
                'note' => $note,
                'cancel_reason' => $cancelReason,
            ], static fn ($value) => $value !== null),
            note: 'Admin cập nhật trạng thái đơn mua ngọc',
        );

        return $order;
    }

    /** @return array{before: int, after: int} */
    private function creditRefundOnce(
        GemTransaction $order,
        int $amount,
        string $reason,
        string $idempotencyKey,
    ): array {
        $existing = Transaction::withoutUserOwnedScope()
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return [
                'before' => (int) $existing->balance_before,
                'after' => (int) $existing->balance_after,
            ];
        }

        $user = User::query()->whereKey($order->user_id)->lockForUpdate()->firstOrFail();
        $balanceBefore = (int) $user->balance;

        if ($balanceBefore > TransactionService::MAX_BALANCE - $amount) {
            throw ValidationException::withMessages([
                'balance' => 'Số dư sau hoàn tiền vượt giới hạn lưu trữ.',
            ]);
        }

        $balanceAfter = $balanceBefore + $amount;
        $user->forceFill(['balance' => $balanceAfter])->save();

        TransactionService::log(
            userId: (int) $user->id,
            type: Transaction::TYPE_GEM_ORDER_REFUND,
            amount: $amount,
            description: "Hoàn tiền đơn mua ngọc #{$order->id}: {$reason}",
            performedBy: auth()->id(),
            related: $order,
            relatedId: $order->id,
            oldBalance: $balanceBefore,
            newBalance: $balanceAfter,
            idempotencyKey: $idempotencyKey,
            metadata: [
                'source' => 'admin',
                'reason' => $reason,
            ],
        );

        return ['before' => $balanceBefore, 'after' => $balanceAfter];
    }

    private function canTransitionStatus(GemTransaction $order, string $targetStatus): bool
    {
        if ($order->refunded_at !== null || $order->status === GemTransaction::STATUS_REFUNDED) {
            return false;
        }

        $allowed = [
            GemTransaction::STATUS_PENDING => [
                GemTransaction::STATUS_PROCESSING,
                GemTransaction::STATUS_CANCELLED,
            ],
            GemTransaction::STATUS_PROCESSING => [
                GemTransaction::STATUS_COMPLETED,
                GemTransaction::STATUS_CANCELLED,
            ],
            GemTransaction::STATUS_CANCELLED => [
                GemTransaction::STATUS_PENDING,
                GemTransaction::STATUS_PROCESSING,
            ],
            GemTransaction::STATUS_COMPLETED => [],
            GemTransaction::STATUS_REFUNDED => [],
        ];

        return in_array($targetStatus, $allowed[$order->status] ?? [], true);
    }

    private function notifyStatus(GemTransaction $order): void
    {
        $this->realtime->orderStatus(
            userId: (int) $order->user_id,
            orderType: 'gem',
            orderId: (int) $order->id,
            status: (string) $order->status,
            botId: null,
        );
    }
}
