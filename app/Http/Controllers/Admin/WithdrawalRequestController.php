<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Withdrawal\WithdrawalRequestResource;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\TelegramService;
use App\Services\TransactionService;
use App\Support\AdminTableSearch;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class WithdrawalRequestController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $query = WithdrawalRequest::with([
            'user:id,username,email',
            'user.roles:id,name',
            'approver:id,username',
        ]);

        // Apply filters
        $this->applyFilters($query, $request);

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $withdrawals = $query->paginate(20)->withQueryString();

        // Get filtered stats
        $stats = $this->getFilteredStats($request);

        return Inertia::render('Admin/Withdrawals/Index', [
            'withdrawals' => WithdrawalRequestResource::collection($withdrawals),
            'filters' => $request->only(['search', 'status', 'date_from', 'date_to']),
            'stats' => $stats,
        ]);
    }

    /**
     * Apply filters to query
     */
    private function applyFilters($query, Request $request)
    {
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

        AdminTableSearch::applyPreset($query, $request->input('search'), 'withdrawals');

        return $query;
    }

    /**
     * Get statistics with filters applied
     */
    private function getFilteredStats(Request $request)
    {
        $baseQuery = WithdrawalRequest::query();
        $this->applyFilters($baseQuery, $request);

        return [
            'total_requests' => (clone $baseQuery)->count(),
            'pending_requests' => (clone $baseQuery)->where('status', 'pending')->count(),
            'approved_requests' => (clone $baseQuery)->where('status', 'approved')->count(),
            'rejected_requests' => (clone $baseQuery)->where('status', 'rejected')->count(),
            'paid_requests' => (clone $baseQuery)->where('status', 'paid')->count(),
            'total_amount' => (clone $baseQuery)->sum('amount'),
            'total_fee' => (clone $baseQuery)->sum('fee'),
            'total_net_amount' => (clone $baseQuery)->sum('net_amount'),
            'paid_amount' => (clone $baseQuery)->where('status', 'paid')->sum('net_amount'),
            'pending_amount' => (clone $baseQuery)->where('status', 'pending')->sum('amount'),
            'today_requests' => (clone $baseQuery)->whereDate('created_at', today())->count(),
            'today_amount' => (clone $baseQuery)->whereDate('created_at', today())->sum('amount'),
        ];
    }

    public function store(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:10000',
            'bank_name' => 'required|string|max:255',
            'bank_account_number' => 'required|string|max:100',
            'bank_account_name' => 'required|string|max:255',
            'note_user' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();

        try {
            $user = User::where('id', Auth::id())
                ->where('balance', '>=', $request->amount)
                ->lockForUpdate()
                ->firstOrFail();
            $oldBalance = (int) $user->balance;

            $affected = User::where('id', $user->id)
                ->where('balance', '>=', $request->amount)
                ->decrement('balance', $request->amount);

            if (! $affected) {
                DB::rollBack();

                return redirect()->back()->withErrors(['amount' => 'Số dư không đủ để thực hiện rút tiền.']);
            }

            $user->refresh();
            $newBalance = $user->balance;

            $withdraw = WithdrawalRequest::create([
                'user_id' => $user->id,
                'amount' => $request->amount,
                'bank_name' => $request->bank_name,
                'bank_account_number' => $request->bank_account_number,
                'bank_account_name' => $request->bank_account_name,
                'note_user' => $request->note_user,
                'status' => 'pending',
            ]);

            // ✅ FIX: Log transaction với amount âm cho withdraw
            TransactionService::log(
                userId: $user->id,
                type: 'withdraw',
                amount: -$request->amount, // ✅ Số âm cho withdraw
                description: "Rút tiền ctv - {$user->username} - Số dư: {$newBalance}",
                performedBy: auth()->id(),
                related: $withdraw,
                relatedId: $withdraw->id,
                oldBalance: $oldBalance,
                newBalance: $newBalance,
                idempotencyKey: "withdrawal-request:{$withdraw->id}:debit",
                metadata: [
                    'source' => 'admin',
                    'bank_name' => $withdraw->bank_name,
                    'status' => $withdraw->status,
                ],
            );

            DB::commit();

            // Gửi thông báo Telegram qua queue
            (new TelegramService)->sendQueue(
                "
        🛒 <b>Rút Tiền</b>\n
        👤 user : <b>{$user->username}</b>\n
        💰 số tiền: ".number_format(-$request->amount, 0, ',', '.')." VNĐ\n
        ⏰ ".now()->format('H:i d/m/Y')
            );

            return redirect()->back()->with('success', 'Yêu cầu rút tiền đã được gửi.');
        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()->back()->withErrors(['error' => 'Có lỗi xảy ra, vui lòng thử lại sau.']);
        }
    }

    public function approve(Request $request, WithdrawalRequest $withdrawal)
    {
        if ($withdrawal->status !== 'pending') {
            return back()->with('error', 'Chỉ có thể duyệt yêu cầu đang chờ xử lý');
        }

        $validated = $request->validate([
            'fee' => 'required|numeric|min:0',
            'fee_type' => 'required|in:amount,percentage', // amount hoặc percentage
            'note' => 'nullable|string|max:500',
            'payment_proof' => 'nullable|image|max:5120', // 5MB
        ]);

        DB::beginTransaction();
        try {
            // Tính phí
            $fee = 0;
            if ($validated['fee_type'] === 'percentage') {
                // Tính phí theo %
                $fee = ($withdrawal->amount * $validated['fee']) / 100;
            } else {
                // Phí cố định
                $fee = $validated['fee'];
            }

            // Tính số tiền thực nhận
            $netAmount = $withdrawal->amount - $fee;

            if ($netAmount <= 0) {
                throw new \Exception('Số tiền thực nhận phải lớn hơn 0');
            }

            // Upload payment proof nếu có
            $paymentProofPath = null;
            if ($request->hasFile('payment_proof')) {
                $paymentProofPath = $request->file('payment_proof')->store('withdrawals/proofs', 'public');
            }

            // Update withdrawal
            $withdrawal->update([
                'status' => 'approved',
                'fee' => $fee,
                'net_amount' => $netAmount,
                'note' => $validated['note'] ?? null,
                'payment_proof' => $paymentProofPath,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            DB::commit();

            return back()->with('success', 'Đã duyệt yêu cầu rút tiền thành công');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    public function reject(Request $request, WithdrawalRequest $withdrawal)
    {
        if ($withdrawal->status !== 'pending') {
            return back()->with('error', 'Chỉ có thể từ chối yêu cầu đang chờ xử lý');
        }

        $validated = $request->validate([
            'note' => 'required|string|max:1000',
            'refund_amount' => 'nullable|numeric|min:0|max:'.$withdrawal->amount,
        ]);

        DB::beginTransaction();
        try {
            $withdrawal = WithdrawalRequest::query()
                ->whereKey($withdrawal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($withdrawal->status !== 'pending') {
                throw new \RuntimeException('Chỉ có thể từ chối yêu cầu đang chờ xử lý');
            }

            $withdrawal->update([
                'status' => 'rejected',
                'note' => $validated['note'],
                'approved_by' => auth()->id(),
                'rejected_at' => now(),
            ]);

            // Hoàn tiền nếu có
            $refundAmount = $validated['refund_amount'] ?? $withdrawal->amount;
            if ($refundAmount > 0) {
                $user = User::query()->whereKey($withdrawal->user_id)->lockForUpdate()->firstOrFail();
                $oldBalance = (int) $user->balance;
                $user->increment('balance', $refundAmount);
                $newBalance = $oldBalance + (int) $refundAmount;

                // Log transaction hoàn tiền (tùy chọn)
                TransactionService::log(
                    userId: $withdrawal->user_id,
                    type: 'refund_withdrawal',
                    amount: $refundAmount,
                    description: "Hoàn tiền huỷ rút tiền #{$withdrawal->id} ",
                    performedBy: auth()->id(),
                    related: $withdrawal,
                    relatedId: $withdrawal->id,
                    oldBalance: $oldBalance,
                    newBalance: $newBalance,
                    idempotencyKey: "withdrawal-request:{$withdrawal->id}:refund",
                    metadata: [
                        'source' => 'admin',
                        'status' => $withdrawal->status,
                        'refund_amount' => (int) $refundAmount,
                        'reason' => $validated['note'],
                    ],
                );
            }

            DB::commit();

            return back()->with('success', "Đã từ chối yêu cầu rút tiền và hoàn {$refundAmount} VNĐ");
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->with('error', 'Có lỗi xảy ra: '.$e->getMessage());
        }
    }

    public function markPaid(Request $request, WithdrawalRequest $withdrawal)
    {
        if ($withdrawal->status !== 'approved') {
            return back()->with('error', 'Chỉ có thể đánh dấu đã trả cho yêu cầu đã duyệt');
        }

        $validated = $request->validate([
            'payment_proof' => 'nullable|image|max:5120',
            'note' => 'nullable|string|max:500',
        ]);

        // Upload payment proof nếu có
        $paymentProofPath = $withdrawal->payment_proof;
        if ($request->hasFile('payment_proof')) {
            $paymentProofPath = $request->file('payment_proof')->store('withdrawals/proofs', 'public');
        }

        $withdrawal->update([
            'status' => 'paid',
            'payment_proof' => $paymentProofPath,
            'note' => $validated['note'] ?? $withdrawal->note,
            'paid_at' => now(),
        ]);

        return back()->with('success', 'Đã đánh dấu là đã thanh toán');
    }
}
