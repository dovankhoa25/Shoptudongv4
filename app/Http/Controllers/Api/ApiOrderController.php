<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\GoldTransactionResource;
use App\Models\GoldPrice;
use App\Models\GoldTransaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function store(Request $request)
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
                'pure_gold_qty' => $pureGoldQty,
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
