<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceOrder\ReceiverServiceOrderResource;
use App\Http\Resources\ServiceOrder\ServiceOrderResource;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\TransactionService;
use App\Support\AdminTableSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ServiceOrderController extends Controller
{
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
        $user = Auth::user();

        DB::transaction(function () use ($id, $user) {
            // Lấy và lock
            $order = ServiceOrder::withoutReceiverOwnedScope()
                ->where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->receiver_id !== null) {
                return redirect()->back()
                    ->with('error', 'Đã có người nhận rồi');
            }

            $order->receiver_id = $user->id;
            $order->status = 'approved';
            $order->save();
        });

        $order = ServiceOrder::withoutReceiverOwnedScope()->with('receiver:id,username')->find($id);

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
