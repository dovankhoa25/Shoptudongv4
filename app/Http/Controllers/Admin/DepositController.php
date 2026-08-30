<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Deposit\SaveCardTypeRequest;
use App\Http\Resources\Admin\Deposit\AtmTopupResource;
use App\Http\Resources\Admin\Deposit\CardResource;
use App\Http\Resources\Admin\Deposit\CardTypeResource;
use App\Models\AtmTopup;
use App\Models\Card;
use App\Models\CardType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DepositController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'card_search' => ['nullable', 'string', 'max:191'],
            'card_status' => ['nullable', Rule::in([
                Card::STATUS_PENDING,
                Card::STATUS_CONFIRMED,
                Card::STATUS_COMPLETED,
                Card::STATUS_FAILED,
            ])],
            'card_card_type_id' => ['nullable', 'integer', Rule::exists('card_types', 'id')],
            'bank_search' => ['nullable', 'string', 'max:191'],
            'bank_gateway' => ['nullable', 'string', 'max:50'],
        ]);

        $cardSearch = trim((string) ($validated['card_search'] ?? ''));
        $bankSearch = trim((string) ($validated['bank_search'] ?? ''));

        return Inertia::render('Admin/Deposits/Index', [
            'cardTypes' => CardTypeResource::collection(
                CardType::query()->withCount('cards')->orderBy('telco')->get()
            ),
            'cards' => fn () => CardResource::collection(
                Card::query()
                    ->with(['user:id,username,email,deleted_at', 'cardType:id,telco'])
                    ->when($cardSearch !== '', function ($query) use ($cardSearch) {
                        $query->where(function ($query) use ($cardSearch) {
                            $query->where('code', 'like', "%{$cardSearch}%")
                                ->orWhere('serial', 'like', "%{$cardSearch}%")
                                ->orWhere('trans_id', 'like', "%{$cardSearch}%")
                                ->orWhereHas('user', fn ($query) => $query
                                    ->where('username', 'like', "%{$cardSearch}%")
                                    ->orWhere('email', 'like', "%{$cardSearch}%"));
                        });
                    })
                    ->when(
                        $validated['card_status'] ?? null,
                        fn ($query, string $status) => $query->where('status', $status)
                    )
                    ->when(
                        $validated['card_card_type_id'] ?? null,
                        fn ($query, int $cardTypeId) => $query->where('card_type_id', $cardTypeId)
                    )
                    ->latest('id')
                    ->paginate($this->perPage($request, 'card_per_page'), ['*'], 'card_page')
                    ->withQueryString()
            ),
            'bankTopups' => fn () => AtmTopupResource::collection(
                AtmTopup::query()
                    ->with('user:id,username,email,deleted_at')
                    ->when($bankSearch !== '', function ($query) use ($bankSearch) {
                        $query->where(function ($query) use ($bankSearch) {
                            $query->where('provider_transaction_id', 'like', "%{$bankSearch}%")
                                ->orWhere('reference_code', 'like', "%{$bankSearch}%")
                                ->orWhere('payment_code', 'like', "%{$bankSearch}%")
                                ->orWhere('content', 'like', "%{$bankSearch}%")
                                ->orWhereHas('user', fn ($query) => $query
                                    ->where('username', 'like', "%{$bankSearch}%")
                                    ->orWhere('email', 'like', "%{$bankSearch}%"));
                        });
                    })
                    ->when(
                        $validated['bank_gateway'] ?? null,
                        fn ($query, string $gateway) => $query->where('gateway', $gateway)
                    )
                    ->latest('id')
                    ->paginate($this->perPage($request, 'bank_per_page'), ['*'], 'bank_page')
                    ->withQueryString()
            ),
            'filters' => [
                'card' => [
                    'search' => $validated['card_search'] ?? null,
                    'status' => $validated['card_status'] ?? null,
                    'card_type_id' => $validated['card_card_type_id'] ?? null,
                ],
                'bank' => [
                    'search' => $validated['bank_search'] ?? null,
                    'gateway' => $validated['bank_gateway'] ?? null,
                ],
            ],
            'gateways' => AtmTopup::query()
                ->whereNotNull('gateway')
                ->distinct()
                ->orderBy('gateway')
                ->pluck('gateway'),
            'stats' => [
                'bank_total' => (int) AtmTopup::query()->sum('amount'),
                'card_total' => (int) Card::query()->where('status', Card::STATUS_COMPLETED)->sum('amount_user'),
                'pending_cards' => Card::query()->where('status', Card::STATUS_PENDING)->count(),
                'active_card_types' => CardType::query()->where('status', true)->count(),
            ],
            'can' => [
                'manage_card_types' => $request->user()->hasRole('super-admin')
                    || $request->user()->can(Permission::DepositsManageCardTypes->value),
            ],
        ]);
    }

    public function storeCardType(SaveCardTypeRequest $request): JsonResponse
    {
        $cardType = CardType::query()->create($request->validated());

        return (new CardTypeResource($cardType->loadCount('cards')))
            ->response()
            ->setStatusCode(201);
    }

    public function updateCardType(SaveCardTypeRequest $request, CardType $cardType): JsonResponse
    {
        $cardType->update($request->validated());

        return (new CardTypeResource($cardType->refresh()->loadCount('cards')))->response();
    }

    private function perPage(Request $request, string $key): int
    {
        return min(max($request->integer($key, 20), 1), 100);
    }
}
