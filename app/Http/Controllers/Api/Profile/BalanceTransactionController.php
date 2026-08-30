<?php

namespace App\Http\Controllers\Api\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Profile\ListBalanceTransactionsRequest;
use App\Http\Resources\Profile\BalanceTransactionResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BalanceTransactionController extends Controller
{
    public function index(ListBalanceTransactionsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $transactions = $request->user()
            ->transactions()
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when($filters['direction'] ?? null, fn ($query, string $direction) => $query
                ->where('amount', $direction === 'credit' ? '>' : '<', 0))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query
                ->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query
                ->where('created_at', '<=', $to.' 23:59:59'))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return BalanceTransactionResource::collection($transactions);
    }
}
