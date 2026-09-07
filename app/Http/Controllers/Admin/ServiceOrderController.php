<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceOrder\ReceiverServiceOrderResource;
use App\Http\Resources\ServiceOrder\ServiceOrderResource;
use App\Models\ChatConversation;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\Chat\ChatManager;
use App\Services\Chat\ChatRealtimeNotifier;
use App\Services\TransactionService;
use App\Support\AdminTableSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ServiceOrderController extends Controller
{
    public function __construct(
        private readonly ChatManager $chatManager,
        private readonly ChatRealtimeNotifier $chatRealtime,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = $data['per_page'] ?? 20;

        $orders = ServiceOrder::withoutReceiverOwnedScope()
            ->where('status', 'pending')
            ->with(['user:id,username', 'receiver:id,username', 'service:id,name,processing_time,warranty'])
            ->when($request->has('status'), function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->forUserCategories($user)
            ->when($request->filled('search'), fn ($query) => AdminTableSearch::applyPreset($query, $request->input('search'), 'serviceOrders'))
            ->latest()
            ->paginate($perPage);

        return Inertia::render('Admin/ServiceOrders/Index', [
            'service_orders' => ServiceOrderResource::collection($orders),
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    public function accept(Request $request, $id)
    {
        /** @var User $user */
        $user = $request->user();

        $result = DB::transaction(function () use ($id, $user): array {
            $order = ServiceOrder::withoutReceiverOwnedScope()
                ->forUserCategories($user)
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->receiver_id !== null) {
                return ['error' => 'Đã có người nhận đơn này rồi.', 'conversation' => null];
            }

            if ($order->status !== 'pending') {
                return ['error' => 'Đơn không còn ở trạng thái chờ nhận.', 'conversation' => null];
            }

            $order->receiver_id = $user->id;
            $order->status = 'approved';
            $order->save();

            $conversation = null;
            if ($user->status === User::STATUS_ACTIVE
                && $user->isChatAgent()
                && $user->can('chats.view')
                && $user->can('chats.reply')) {
                $conversation = ChatConversation::query()
                    ->where('customer_id', $order->user_id)
                    ->where('subject_type', 'service_order')
                    ->where('subject_id', $order->id)
                    ->where(function ($query): void {
                        $query
                            ->whereNull('assigned_to_id')
                            ->orWhereHas('assignee.roles', fn ($roles) => $roles
                                ->whereIn('name', ['admin', 'super-admin']));
                    })
                    ->lockForUpdate()
                    ->first();

                if ($conversation) {
                    $conversation = $this->chatManager->assign($conversation, $user, $user, false);
                }
            }

            return ['error' => null, 'conversation' => $conversation];
        });

        if ($result['error']) {
            return redirect()->back()->with('error', $result['error']);
        }

        /** @var ChatConversation|null $conversation */
        $conversation = $result['conversation'];
        if ($conversation) {
            $this->chatRealtime->inbox(
                'assigned',
                $conversation,
                [
                    'id' => (int) $conversation->id,
                    'customer_id' => (int) $conversation->customer_id,
                    'assigned_to_id' => (int) $conversation->assigned_to_id,
                    'category' => $conversation->category,
                    'subject_type' => $conversation->subject_type,
                    'subject_id' => (int) $conversation->subject_id,
                    'status' => $conversation->status,
                    'last_message_at' => $conversation->last_message_at?->toIso8601String(),
                ],
            );
        }

        return redirect()->back()->with('message', 'Nhận đơn thành công!');
    }

    public function getReceiverOrder(Request $request)
    {
        // Validate filters
        $request->validate([
            'search' => 'nullable|string|max:255',
            'account' => 'nullable|string|max:255',
            'status' => 'nullable|in:approved,processing,completed,failed,cancelled',
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],

        ]);
        $perPage = $request->input('per_page', 20);
        // Get filters from request
        $filters = $request->only(['search', 'account', 'status']);

        // Build query
        $query = ServiceOrder::whereNotNull('receiver_id')
            ->where('status', '!=', 'pending')
            ->with([
                'user:id,username',
                'receiver:id,username',
                'service:id,name,processing_time,warranty',
            ]);
        // Apply search filter
        if (! empty($filters['search'])) {
            AdminTableSearch::applyPreset($query, $filters['search'], 'serviceOrders');
        }

        // ✅ Filter theo account (tài khoản giao dịch)
        if (! empty($filters['account'])) {
            $account = $filters['account'];
            $query->where('account', 'like', "%{$account}%");
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $query->orderByRaw("CASE WHEN status = 'approved' THEN 0 ELSE 1 END")
            ->latest();

        $orders = $query->paginate($perPage);

        return Inertia::render('Admin/ServiceOrders/Receiver', [
            'service_orders' => ReceiverServiceOrderResource::collection($orders),
            'filters' => $filters,
        ]);
    }

    public function updateReceiverOrder(Request $request, $id)
    {
        DB::transaction(function () use ($id) {
            $order = ServiceOrder::lockForUpdate()->findOrFail($id);

            if ($order->status === 'completed') {
                throw new \Exception('Đơn đã hoàn thành rồi.');
            }

            if ($order->status !== 'approved') {
                throw new \Exception('Chỉ những đơn ở trạng thái "approved" mới có thể hoàn thành.');
            }

            $order->description = trim($order->description.' | Hoàn thành ');
            $order->status = 'completed';
            $order->save();

            if ($order->receiver) {
                $receiver = User::query()->whereKey($order->receiver->id)->lockForUpdate()->firstOrFail();
                $balanceBefore = (int) $receiver->balance;
                $amount = (int) ($order->service_price ?? 0);
                $receiver->increment('balance', $amount);
                $balanceAfter = $balanceBefore + $amount;

                TransactionService::log(
                    userId: $receiver->id,
                    type: 'sell_service',
                    amount: abs($amount),
                    description: "Nhận tiền hoàn thành đơn dịch vụ #{$order->id}",
                    performedBy: auth()->id(),
                    related: $order,
                    relatedId: $order->id,
                    oldBalance: $balanceBefore,
                    newBalance: $balanceAfter,
                    idempotencyKey: "service-order-completion:{$order->id}:receiver:{$receiver->id}",
                    metadata: [
                        'source' => 'admin',
                        'service_id' => $order->service_id,
                        'status' => $order->status,
                    ],
                );
            }
        });

        return redirect()->back()->with('success', 'Đơn đã được hoàn thành & cộng tiền cho bạn!');
    }

    public function cancelReceiverOrder(Request $request, $id)
    {
        $validated = $request->validate([
            'cancel_reason' => ['required', 'string', 'max:255'],
        ]);

        try {
            DB::transaction(function () use ($id, $validated) {
                $order = ServiceOrder::lockForUpdate()->findOrFail($id);

                if ($order->status === 'completed') {
                    throw new \Exception('Đơn đã hoàn thành rồi.');
                }

                if ($order->status !== 'approved') {
                    throw new \Exception('Chỉ những đơn ở trạng thái "approved" mới có thể hoàn thành.');
                }
                $order->status = 'rejected';
                $order->description = trim($order->description.' | Hủy đơn: '.$validated['cancel_reason']);
                $order->save();

                if ($order->user && $order->service_price) {
                    $user = User::query()->whereKey($order->user->id)->lockForUpdate()->firstOrFail();
                    $balanceBefore = (int) $user->balance;
                    $amount = (int) $order->service_price;
                    $user->increment('balance', $amount);
                    $balanceAfter = $balanceBefore + $amount;

                    TransactionService::log(
                        userId: $user->id,
                        type: 'refund_service',
                        amount: abs($amount),
                        description: "Hoàn tiền huỷ đơn dịch vụ #{$order->id} - {$validated['cancel_reason']}",
                        performedBy: auth()->id(),
                        related: $order,
                        relatedId: $order->id,
                        oldBalance: $balanceBefore,
                        newBalance: $balanceAfter,
                        idempotencyKey: "service-order-cancellation-refund:{$order->id}:user:{$user->id}",
                        metadata: [
                            'source' => 'admin',
                            'service_id' => $order->service_id,
                            'status' => $order->status,
                            'cancel_reason' => $validated['cancel_reason'],
                        ],
                    );
                }
            });

            return redirect()->back()->with('success', 'Huỷ đơn thành công & hoàn tiền cho khách!');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
