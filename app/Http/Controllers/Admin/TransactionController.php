<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\Transaction\TransactionCollection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use App\Support\AdminTableSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'type' => ['nullable', Rule::in(Transaction::types())],
        ]);
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $transactions = Transaction::query()
            ->with([
                'user:id,username,email,deleted_at',
                'performer:id,username,deleted_at',
            ])
            ->when($filters['search'] ?? null, function ($query, string $search) {
                AdminTableSearch::applyPreset($query, $search, 'transactions');
            })
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('Admin/Transactions/Index', [
            'transactions' => new TransactionCollection($transactions),
            'filters' => $filters,
        ]);
    }

    /**
     * Compatibility endpoint used by the imported admin UI. New clients should
     * prefer POST /admin/users/{user}/balance, which requires an explicit
     * idempotency key.
     */
    public function addMoney(Request $request, TransactionService $transactions): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:bonus,admin_adjust'],
        ]);

        $target = User::query()->findOrFail($data['user_id']);
        $direction = $data['type'] === 'bonus' ? 'credit' : 'debit';
        $description = trim((string) ($data['description'] ?? '')) ?: ($direction === 'credit'
            ? 'Admin cộng tiền'
            : 'Admin trừ tiền');

        $result = $transactions->adjustBalance(
            $target,
            $request->user(),
            $direction,
            (int) $data['amount'],
            $description,
            'legacy-admin-'.Str::uuid(),
            [
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'source' => 'legacy-admin-ui',
            ],
        );

        return response()->json([
            'success' => true,
            'message' => 'Điều chỉnh số dư thành công.',
            'balance' => $result['transaction']->balance_after,
            'transaction_id' => $result['transaction']->id,
        ]);
    }
}
