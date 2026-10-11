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
use App\Support\AdminTableSearch;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            'bank_provider' => ['nullable', Rule::in(['sepay', 'dvkpay'])],
            'bank_gateway' => ['nullable', 'string', 'max:50'],
            'bank_start_date' => ['nullable', 'date_format:Y-m-d'],
            'bank_end_date' => ['nullable', 'date_format:Y-m-d'],
            'bank_all_dates' => ['nullable', 'boolean'],
            'bank_status' => ['nullable', Rule::in([AtmTopup::STATUS_COMPLETED, AtmTopup::STATUS_NEEDS_REVIEW])],
        ]);

        $cardSearch = trim((string) ($validated['card_search'] ?? ''));
        $bankSearch = trim((string) ($validated['bank_search'] ?? ''));

        $today = CarbonImmutable::today('Asia/Ho_Chi_Minh')->toDateString();
        $bankStartDate = $validated['bank_start_date'] ?? $today;
        $bankEndDate = $validated['bank_end_date'] ?? $bankStartDate;
        $bankAllDates = $request->boolean('bank_all_dates');

        if ($bankEndDate < $bankStartDate) {
            throw ValidationException::withMessages([
                'bank_end_date' => 'Ngày kết thúc phải bằng hoặc sau ngày bắt đầu.',
            ]);
        }

        // Both webhook providers store bank timestamps in the application timezone.
        // Use an exclusive next-day bound to include the entire end date.
        $bankQuery = AtmTopup::query()
            ->when(! $bankAllDates, fn ($query) => $query
                ->where('transaction_at', '>=', CarbonImmutable::parse($bankStartDate, 'Asia/Ho_Chi_Minh')->startOfDay()->setTimezone(config('app.timezone')))
                ->where('transaction_at', '<', CarbonImmutable::parse($bankEndDate, 'Asia/Ho_Chi_Minh')->addDay()->startOfDay()->setTimezone(config('app.timezone'))))
            ->when($bankSearch !== '', function ($query) use ($bankSearch) {
                AdminTableSearch::applyPreset($query, $bankSearch, 'bankTopups');
            })
            ->when($validated['bank_gateway'] ?? null, fn ($query, string $gateway) => $query->where('gateway', $gateway))
            ->when($validated['bank_status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['bank_provider'] ?? null, fn ($query, string $provider) => $query
                ->whereIn('provider', $provider === 'dvkpay' ? ['dvkpay', 'gpmpay'] : ['sepay']));

        return Inertia::render('Admin/Deposits/Index', [
            'cardTypes' => CardTypeResource::collection(
                CardType::query()->withCount('cards')->orderBy('telco')->get()
            ),
            'cards' => fn () => CardResource::collection(
                Card::query()
                    ->with(['user:id,username,email,deleted_at', 'cardType:id,telco'])
                    ->when($cardSearch !== '', function ($query) use ($cardSearch) {
                        AdminTableSearch::applyPreset($query, $cardSearch, 'cards');
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
                (clone $bankQuery)
                    ->with('user:id,username,email,deleted_at')
                    ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [AtmTopup::STATUS_NEEDS_REVIEW])
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
                    'provider' => $validated['bank_provider'] ?? null,
                    'start_date' => $bankStartDate,
                    'end_date' => $bankEndDate,
                    'all_dates' => (int) $bankAllDates,
                    'status' => $validated['bank_status'] ?? null,
                ],
            ],
            'bankToday' => $today,
            'gateways' => AtmTopup::query()
                ->whereNotNull('gateway')
                ->distinct()
                ->orderBy('gateway')
                ->pluck('gateway'),
            'stats' => [
                'bank_total' => (int) (clone $bankQuery)->where('status', AtmTopup::STATUS_COMPLETED)->sum('amount'),
                'pending_bank_topups' => AtmTopup::query()->where('status', AtmTopup::STATUS_NEEDS_REVIEW)->count(),
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
