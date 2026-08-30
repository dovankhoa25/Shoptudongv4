<?php

namespace App\Http\Controllers\Api\Profile;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\NickOrderResource;
use App\Http\Resources\Profile\GoldTransactionResource;
use App\Http\Resources\Profile\GoldWalletResource;
use App\Http\Resources\Profile\LuckyNumberBetResource;
use App\Http\Resources\Profile\WalletTransactionResource;
use App\Http\Resources\Profile\WalletTransferResource;
use App\Models\NickOrder;
use App\Scopes\UserOwnedScope;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function goldWallets(Request $request)
    {
        return GoldWalletResource::collection($request->user()->goldWallets()->orderBy('server_id')->get());
    }

    public function goldTransactions(Request $request)
    {
        return GoldTransactionResource::collection($request->user()->goldTransactions()->latest()->paginate($this->perPage($request)));
    }

    public function walletTransactions(Request $request)
    {
        return WalletTransactionResource::collection($request->user()->goldWalletTransactions()->latest()->paginate($this->perPage($request)));
    }

    public function walletTransfers(Request $request)
    {
        return WalletTransferResource::collection($request->user()->goldWalletTransfers()->latest()->paginate($this->perPage($request)));
    }

    public function luckyNumberBets(Request $request)
    {
        return LuckyNumberBetResource::collection($request->user()->luckyNumberBets()->latest('placed_at')->paginate($this->perPage($request)));
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 15), 1), 100);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'in:pending,completed,refunded'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = NickOrder::with([
            'nick' => fn ($query) => $query->withoutGlobalScope(UserOwnedScope::class),
            'seller',
        ])
            ->where('buyer_id', $request->user()->id)
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    if (ctype_digit($search)) {
                        $query->where('id', (int) $search)
                            ->orWhere('nick_id', (int) $search);
                    }

                    $query->orWhereHas('nick', function ($nickQuery) use ($search): void {
                        $nickQuery->withoutGlobalScope(UserOwnedScope::class)
                            ->where(function ($nickQuery) use ($search): void {
                                $nickQuery->where('account_name', 'like', "%{$search}%")
                                    ->orWhere('description', 'like', "%{$search}%");
                            });
                    });
                });
            })
            ->when($validated['status'] ?? null, function ($query) use ($validated): void {
                $query->where('status', $validated['status']);
            })
            ->latest()
            ->paginate($this->perPage($request))
            ->withQueryString();

        return NickOrderResource::collection($orders);
    }
}
