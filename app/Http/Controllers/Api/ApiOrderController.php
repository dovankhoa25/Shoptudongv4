<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\GoldTransactionResource;
use App\Models\GoldPrice;
use App\Models\GoldTransaction;
use App\Models\User;
use App\Services\GoldBarOrderQuoteService;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApiOrderController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'in:pending,processing,completed,cancelled'],
            'type' => ['nullable', 'in:order,import'],
        ]);

        $query = GoldTransaction::query()
            ->where('user_id', $request->user()->id)
            ->with('server');

        if ($type = $validated['type'] ?? null) {
            $query->where('type', $type);
        }

        if ($status = $validated['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(function ($query) use ($search): void {
                $query->where('character_name', 'like', "%{$search}%");

                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }
            });
        }

        $perPage = (int) ($validated['per_page'] ?? $validated['limit'] ?? 10);
        $orders = $query
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return GoldTransactionResource::collection($orders)->additional([
            'success' => true,
        ]);
    }

    public function store(Request $request, GoldBarOrderQuoteService $quoteService)
    {
        $validated = $request->validate([
            'server_id' => 'required|exists:servers,id',
            'character_name' => 'required|string|min:3|max:191',
            'money_amount' => 'required|integer|min:5000',
            // Giữ field để frontend cũ vẫn tương thích, nhưng production chỉ nhận thỏi.
            'gold_type' => 'sometimes|in:bar',
        ]);

        $user = $request->user();
        $characterName = strtolower(str_replace(' ', '', $validated['character_name']));

        $hasPendingOrder = GoldTransaction::query()
            ->where('user_id', $user->id)
            ->where('server_id', $validated['server_id'])
            ->whereRaw("LOWER(REPLACE(character_name, ' ', '')) = ?", [$characterName])
            ->where('type', GoldTransaction::TYPE_ORDER)
            ->whereIn('status', [GoldTransaction::STATUS_PENDING, GoldTransaction::STATUS_PROCESSING])
            ->exists();

        if ($hasPendingOrder) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng hoàn thành đơn hàng trước đó của nhân vật này trên server này!',
            ], 422);
        }

        $goldPrice = GoldPrice::query()
            ->with('server')
            ->where('server_id', $validated['server_id'])
            ->where('status', true)
            ->latest()
            ->first();

        if (! $goldPrice) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy giá vàng cho server này!',
            ], 422);
        }

        if (! $goldPrice->server || ! $goldPrice->server->status) {
            return response()->json([
                'success' => false,
                'message' => 'Server đang tạm ngưng. Không thể tạo đơn.',
            ], 422);
        }

        try {
            $quote = $quoteService->quote(
                requestedAmount: (int) $validated['money_amount'],
                pricePerVnd: (string) $goldPrice->price,
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'money_amount' => $exception->getMessage(),
            ]);
        }

        if ($quote['gold_bar_qty'] < 1) {
            $message = 'Số tiền chưa đủ mua 1 thỏi. Vui lòng nhập tối thiểu '
                .number_format($quote['minimum_amount'], 0, ',', '.').' VNĐ.';

            return response()->json([
                'success' => false,
                'message' => $message,
                'errors' => ['money_amount' => [$message]],
                'data' => $quote,
            ], 422);
        }

        if ($user->roles()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cộng tác viên không được mua vàng.',
            ], 403);
        }

        try {
            $result = DB::transaction(function () use ($user, $validated, $characterName, $quote): array {
                $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $chargedAmount = $quote['charged_amount'];

                if ((int) $lockedUser->balance < $chargedAmount) {
                    throw ValidationException::withMessages([
                        'money_amount' => 'Số dư không đủ.',
                    ]);
                }

                $balanceBefore = (int) $lockedUser->balance;
                $balanceAfter = $balanceBefore - $chargedAmount;
                $lockedUser->forceFill(['balance' => $balanceAfter])->save();

                $order = GoldTransaction::query()->create([
                    'type' => GoldTransaction::TYPE_ORDER,
                    'user_id' => $lockedUser->id,
                    'server_id' => $validated['server_id'],
                    'character_name' => $characterName,
                    // amount_vnd luôn là số tiền thực trừ để huỷ đơn hoàn đúng số tiền.
                    'amount_vnd' => $chargedAmount,
                    'gold_qty' => $quote['actual_gold'],
                    'gold_bar_qty' => $quote['gold_bar_qty'],
                    'pure_gold_qty' => 0,
                    'price_at_transaction' => $quote['price_per_vnd'],
                    'status' => GoldTransaction::STATUS_PENDING,
                    'bot_id' => null,
                    'updated_by' => 'web',
                ]);

                TransactionService::log(
                    userId: $lockedUser->id,
                    type: 'mua vàng',
                    amount: -$chargedAmount,
                    description: "Mua {$quote['gold_bar_qty']} thỏi vàng #{$order->id} - Nhân vật: {$validated['character_name']}",
                    performedBy: $lockedUser->id,
                    related: $order,
                    relatedId: $order->id,
                    oldBalance: $balanceBefore,
                    newBalance: $balanceAfter,
                    idempotencyKey: "gold-order-purchase:{$order->id}:user:{$lockedUser->id}",
                    metadata: [
                        'source' => 'api',
                        'server_id' => $order->server_id,
                        'character_name' => $order->character_name,
                        'requested_amount' => $quote['requested_amount'],
                        'charged_amount' => $chargedAmount,
                        'unused_amount' => $quote['unused_amount'],
                        'gold_qty' => $quote['actual_gold'],
                        'gold_bar_qty' => $quote['gold_bar_qty'],
                    ],
                );

                return ['order' => $order, 'balance_after' => $balanceAfter];
            }, 3);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể tạo đơn mua vàng lúc này. Vui lòng thử lại.',
            ], 500);
        }

        /** @var GoldTransaction $order */
        $order = $result['order'];

        return response()->json([
            'success' => true,
            'message' => 'Tạo đơn mua thỏi vàng thành công.',
            'data' => [
                'order_id' => (int) $order->id,
                'status' => (string) $order->status,
                'requested_amount' => $quote['requested_amount'],
                'charged_amount' => $quote['charged_amount'],
                'unused_amount' => $quote['unused_amount'],
                'balance_after' => (int) $result['balance_after'],
                'price_at_transaction' => $quote['price_per_vnd'],
                'gold_per_bar' => GoldBarOrderQuoteService::GOLD_PER_BAR,
                'gold_bars' => $quote['gold_bar_qty'],
                'actual_gold' => $quote['actual_gold'],
                'next_bar_amount' => $quote['next_bar_amount'],
                // Alias giữ tương thích cho client cũ.
                'gold_total' => $quote['actual_gold'],
                'pure_gold' => 0,
            ],
        ], 201);
    }

    /**
     * Bản mua vàng tươi/thỏi cũ để đối chiếu khi cần rollback.
     * Không gắn route production vì game hiện không cho giao dịch vàng tươi.
     */
    private function storeLegacy(Request $request)
    {
        $validated = $request->validate([
            'server_id' => 'required|exists:servers,id',
            'character_name' => 'required|string|min:3|max:191',
            'money_amount' => 'required|integer|min:5000',
            'gold_type' => 'required|in:bar,pure', // ✅ Thêm validation
        ]);

        $user = $request->user();
        $characterName = strtolower(str_replace(' ', '', $validated['character_name']));

        // ✅ Kiểm tra đơn hàng đang pending hoặc processing
        // $hasPendingOrder = GoldTransaction::where('user_id', $user->id)
        //     ->where('type', 'order')
        //     ->whereIn('status', ['pending', 'processing'])
        //     ->exists();

        // if ($hasPendingOrder) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Vui lòng hoàn thành đơn hàng trước đó!',
        //     ], 422);
        // }
        $hasPendingOrder = GoldTransaction::where('user_id', $user->id)
            ->where('server_id', $validated['server_id'])
            ->whereRaw("LOWER(REPLACE(character_name, ' ', '')) = ?", [
                strtolower(str_replace(' ', '', $validated['character_name'])),
            ])
            ->where('type', 'order')
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($hasPendingOrder) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng hoàn thành đơn hàng trước đó của nhân vật này trên server này!',
            ], 422);
        }

        // Lấy giá bán mới nhất
        $goldPrice = GoldPrice::with('server')
            ->where('server_id', $validated['server_id'])
            ->where('status', true)
            ->latest()
            ->first();

        if (! $goldPrice) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy giá vàng cho server này!',
            ], 422);
        }

        $server = $goldPrice->server;

        if (! $server || ! $server->status) {
            return response()->json([
                'success' => false,
                'message' => 'Server đang tạm ngưng. Không thể tạo đơn.',
            ], 422);
        }

        $pricePer = $goldPrice->price;
        $moneyAmount = $validated['money_amount'];
        $goldType = $validated['gold_type']; // ✅ Lấy loại vàng
        // Tính tổng vàng & tách thỏi bằng BCMath
        $goldQtyTotal = bcmul((string) $moneyAmount, (string) $pricePer);
        $goldPerBar = '37000000';

        // ✅ Logic tính toán dựa trên loại vàng - Logic mới
        $goldPerBar = '37000000'; // 37 triệu
        $maxPureGold = '900000000'; // 500 triệu

        if ($goldType === 'bar') {
            // Thỏi vàng: hiển thị thỏi + vàng lẻ, nhưng vẫn nhận toàn bộ
            $goldBarQty = bcdiv($goldQtyTotal, $goldPerBar, 0); // Số thỏi
            $pureGoldQty = bcmod($goldQtyTotal, $goldPerBar); // Vàng lẻ
            $actualGoldQty = $goldQtyTotal; // Nhận toàn bộ vàng
        } else {
            // Vàng tươi: check giới hạn 500tr
            if (bccomp($goldQtyTotal, $maxPureGold) <= 0) {
                // <= 500tr: toàn bộ là vàng tươi
                $goldBarQty = '0';
                $pureGoldQty = $goldQtyTotal;
                $actualGoldQty = $goldQtyTotal;
            } else {
                // > 500tr: chia thành thỏi + vàng tươi
                $goldBarQty = bcdiv($goldQtyTotal, $goldPerBar, 0); // Số thỏi
                $pureGoldQty = bcmod($goldQtyTotal, $goldPerBar); // Vàng tươi còn lại
                $actualGoldQty = $goldQtyTotal; // Nhận toàn bộ vàng
            }
        }

        // Kiểm tra balance
        if ($user->balance < $moneyAmount) {
            return response()->json([
                'success' => false,
                'message' => 'Số dư không đủ.',
            ], 400);
        }

        if ($user->roles()->exists()) {
            return response()->json(['message' => 'Bạn Là Cộng tác viên không được mua nick nhé'], 403);
        }

        DB::beginTransaction();
        try {
            // Trừ tiền user
            $affected = User::where('id', $user->id)
                ->where('balance', '>=', $moneyAmount)
                ->decrement('balance', $moneyAmount);
            $balanceAfter = $user->fresh()->balance;

            if (! $affected) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Số dư không đủ. phát hiện cố tình bug',
                ], 400);
            }

            $balanceBefore = (int) $balanceAfter + (int) $moneyAmount;

            // Tạo gold transaction
            $order = GoldTransaction::create([
                'type' => 'order',
                'user_id' => $user->id,
                'server_id' => $validated['server_id'],
                'character_name' => $characterName,
                'amount_vnd' => $moneyAmount,
                'gold_qty' => $actualGoldQty, // ✅ Sử dụng actualGoldQty
                'gold_bar_qty' => $goldBarQty,
                // 'pure_gold_qty'       => $pureGoldQty,
                'pure_gold_qty' => 0,
                'price_at_transaction' => $pricePer,
                'status' => 'pending',
                'bot_id' => null,
                'updated_by' => 'web',
            ]);

            // ✅ FIX: Log transaction với amount âm cho withdraw
            TransactionService::log(
                userId: $user->id,
                type: 'mua vàng',
                amount: -$moneyAmount, // ✅ Số âm cho withdraw
                description: "mua vàng {$goldType} #{$order->id} - Nhân vật: {$validated['character_name']} ",
                performedBy: $user->id,
                related: $order,
                relatedId: $order->id,
                oldBalance: $balanceBefore,
                newBalance: $balanceAfter,
                idempotencyKey: "gold-order-purchase:{$order->id}:user:{$user->id}",
                metadata: [
                    'source' => 'api',
                    'server_id' => $order->server_id,
                    'character_name' => $order->character_name,
                    'gold_qty' => (int) $order->gold_qty,
                    'gold_bar_qty' => (int) $order->gold_bar_qty,
                ],
            );
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully.',
                'data' => [
                    'order' => $order,
                    'gold_total' => (int) $actualGoldQty, // ✅ Tổng vàng nhận được
                    'gold_bars' => (int) $goldBarQty, // ✅ Số thỏi vàng
                    'pure_gold' => (int) $pureGoldQty, // ✅ Vàng tươi
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Không thể tạo đơn mua vàng lúc này. Vui lòng thử lại.',
            ], 500);
        }
    }
}
