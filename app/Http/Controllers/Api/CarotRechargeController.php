<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CarotRechargeRequest;
use App\Http\Resources\Api\CarotRechargeResource;
use App\Http\Resources\Api\CarotRechargeStatisticResource;
use App\Models\CarotRecharge;
use App\Models\CarotRechargeStatistic;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CarotRechargeController extends Controller
{
    private const VALID_AMOUNTS = [
        10000,
        20000,
        30000,
        50000,
        100000,
        200000,
        300000,
        500000,
        1000000,
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min(50, max(5, (int) $request->get('per_page', 15)));

        $query = CarotRecharge::where('user_id', $user->id)->latest();

        if ($request->filled('status')) {
            $query->where('status', (string) $request->status);
        }

        if ($request->filled('server_id')) {
            $query->where('server_id', (int) $request->server_id);
        }

        return CarotRechargeResource::collection($query->paginate($perPage))->response();
    }

    public function store(CarotRechargeRequest $request): JsonResponse
    {
        $user = $request->user();
        $items = $this->resolveRechargeItems($request);

        if ($items['errors'] !== []) {
            return response()->json([
                'success' => false,
                'message' => 'Danh sach nap carot khong hop le',
                'errors' => $items['errors'],
            ], 422);
        }

        $totalAmount = collect($items['data'])->sum('amount');

        $result = DB::transaction(function () use ($items, $user, $totalAmount) {
            $lockedUser = User::where('id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedUser->balance < $totalAmount) {
                return [
                    'success' => false,
                    'balance' => (float) $lockedUser->balance,
                    'total_amount' => (float) $totalAmount,
                    'recharges' => collect(),
                ];
            }

            $oldBalance = $lockedUser->balance;
            $newBalance = $oldBalance - $totalAmount;

            $lockedUser->update([
                'balance' => $newBalance,
            ]);

            $recharges = collect($items['data'])->map(function (array $item) use ($lockedUser) {
                $recharge = CarotRecharge::create([
                    'user_id' => $lockedUser->id,
                    'account_name' => $item['account_name'],
                    'server_id' => $item['server_id'],
                    'amount' => $item['amount'],
                    'carot' => $this->convertAmountToCarot($item['amount']),
                    'transaction_code' => $this->makeTransactionCode(),
                    'status' => CarotRecharge::STATUS_PENDING,
                    'message' => 'Yeu cau nap carot dang cho xu ly',
                ]);

                $this->increaseStatistics($recharge);

                return $recharge;
            })->values();

            TransactionService::log(
                userId: $lockedUser->id,
                type: 'carot_recharge',
                amount: -$totalAmount,
                description: 'Tao ' . $recharges->count() . ' don nap carot tu dong',
                performedBy: null,
                related: null,
                relatedId: null,
                oldBalance: $oldBalance,
                newBalance: $newBalance,
                idempotencyKey: 'carot-recharge-batch:'.hash('sha256', $recharges->pluck('id')->implode(',')),
                metadata: [
                    'source' => 'api',
                    'recharge_ids' => $recharges->pluck('id')->all(),
                    'recharge_count' => $recharges->count(),
                ],
            );

            return [
                'success' => true,
                'balance' => (float) $newBalance,
                'total_amount' => (float) $totalAmount,
                'recharges' => $recharges,
            ];
        });

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => 'So du khong du de tao don nap carot',
                'data' => [
                    'balance' => $result['balance'],
                    'total_amount' => $result['total_amount'],
                    'missing_amount' => max(0, $result['total_amount'] - $result['balance']),
                ],
            ], 422);
        }

        $recharges = $result['recharges'];

        return response()->json([
            'success' => true,
            'message' => 'Tao yeu cau nap carot thanh cong',
            'data' => [
                'type' => $request->type,
                'total' => $recharges->count(),
                'total_amount' => $result['total_amount'],
                'balance' => $result['balance'],
                'items' => CarotRechargeResource::collection($recharges)->resolve(),
            ],
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $recharge = CarotRecharge::where('user_id', $user->id)->findOrFail($id);

        return response()->json([
            'data' => (new CarotRechargeResource($recharge))->resolve(),
        ]);
    }

    public function statistics(Request $request): JsonResponse
    {
        $user = $request->user();
        $type = $request->get('type', CarotRechargeStatistic::TYPE_DAILY);

        if (!in_array($type, [
            CarotRechargeStatistic::TYPE_DAILY,
            CarotRechargeStatistic::TYPE_MONTHLY,
            CarotRechargeStatistic::TYPE_YEARLY,
        ], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Loai thong ke khong hop le',
            ], 422);
        }

        $limit = min(100, max(1, (int) $request->get('limit', 30)));
        $statistics = CarotRechargeStatistic::where('user_id', $user->id)
            ->where('type', $type)
            ->whereNull('server_id')
            ->latest('stat_date')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => CarotRechargeStatisticResource::collection($statistics)->resolve(),
        ]);
    }

    private function convertAmountToCarot(int $amount): int
    {
        return (int) floor($amount / 1000);
    }

    private function resolveRechargeItems(CarotRechargeRequest $request): array
    {
        if ($request->type === 'one') {
            $accountName = trim((string) $request->account_name);

            if ($accountName === '') {
                return [
                    'data' => [],
                    'errors' => [[
                        'line' => null,
                        'message' => 'Tai khoan khong duoc de trong',
                        'value' => $request->account_name,
                    ]],
                ];
            }

            return [
                'data' => [[
                    'account_name' => $accountName,
                    'server_id' => (int) $request->server_id,
                    'amount' => (int) $request->amount,
                ]],
                'errors' => [],
            ];
        }

        return $this->parseRechargeList((string) $request->list);
    }

    private function parseRechargeList(string $rawList): array
    {
        $data = [];
        $errors = [];
        $lines = preg_split('/\r\n|\r|\n/', $rawList);

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $columns = array_map('trim', explode('|', $line));

            if (count($columns) !== 3) {
                $errors[] = [
                    'line' => $lineNumber,
                    'message' => 'Sai dinh dang. Dung: tai_khoan|server|menh_gia',
                    'value' => $line,
                ];
                continue;
            }

            [$accountName, $serverId, $amount] = $columns;
            $serverId = filter_var($serverId, FILTER_VALIDATE_INT);
            $amount = filter_var($amount, FILTER_VALIDATE_INT);

            if ($accountName === '') {
                $errors[] = [
                    'line' => $lineNumber,
                    'message' => 'Tai khoan khong duoc de trong',
                    'value' => $line,
                ];
                continue;
            }

            if ($serverId === false || $serverId < 1 || $serverId > 100) {
                $errors[] = [
                    'line' => $lineNumber,
                    'message' => 'Server phai tu 1 den 100',
                    'value' => $line,
                ];
                continue;
            }

            if ($amount === false || !in_array($amount, self::VALID_AMOUNTS, true)) {
                $errors[] = [
                    'line' => $lineNumber,
                    'message' => 'Menh gia khong duoc ho tro',
                    'value' => $line,
                ];
                continue;
            }

            $data[] = [
                'account_name' => $accountName,
                'server_id' => $serverId,
                'amount' => $amount,
            ];
        }

        if ($data === [] && $errors === []) {
            $errors[] = [
                'line' => null,
                'message' => 'Danh sach tai khoan can nap dang trong',
                'value' => null,
            ];
        }

        return [
            'data' => $data,
            'errors' => $errors,
        ];
    }

    private function makeTransactionCode(): string
    {
        do {
            $code = 'CAROT' . now()->format('YmdHis') . Str::upper(Str::random(6));
        } while (CarotRecharge::where('transaction_code', $code)->exists());

        return $code;
    }

    private function increaseStatistics(CarotRecharge $recharge): void
    {
        foreach ($this->statPeriods($recharge->created_at) as $type => $statDate) {
            foreach ([null, $recharge->user_id] as $userId) {
                foreach ([null, $recharge->server_id] as $serverId) {
                    $stat = CarotRechargeStatistic::firstOrCreate([
                        'type' => $type,
                        'stat_date' => $statDate,
                        'user_id' => $userId,
                        'server_id' => $serverId,
                    ]);

                    $stat->increment('total_transactions');
                }
            }
        }
    }

    private function statPeriods(Carbon $date): array
    {
        return [
            CarotRechargeStatistic::TYPE_DAILY => $date->copy()->startOfDay()->toDateString(),
            CarotRechargeStatistic::TYPE_MONTHLY => $date->copy()->startOfMonth()->toDateString(),
            CarotRechargeStatistic::TYPE_YEARLY => $date->copy()->startOfYear()->toDateString(),
        ];
    }
}
