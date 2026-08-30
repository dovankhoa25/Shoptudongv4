<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\GemTransactionResource;
use App\Models\GemPrice;
use App\Models\GemTransaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApiGemOrderController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'in:pending,processing,completed,cancelled,refunded'],
        ]);

        $user = $request->user();

        $query = GemTransaction::with('server');

        $query->where('user_id', $user->id);

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

        return GemTransactionResource::collection($orders)->additional([
            'success' => true,
        ]);
    }

    // public function store(Request $request)
    // {
    //     $validated = $request->validate([
    //         'server_id'      => 'required|exists:servers,id',
    //         'character_name' => 'required|string|max:255',
    //         'money_amount'   => 'required|numeric|min:10000', // ✅ Minimum 10,000 VNĐ cho ngọc
    //     ]);

    //     $user = $request->user();
    //     $characterName = strtolower(str_replace(' ', '', $validated['character_name']));

    //     $hasPendingOrder = GemTransaction::where('user_id', $user->id)
    //         ->whereIn('status', ['pending', 'processing'])
    //         ->exists();
    //     if ($hasPendingOrder) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Vui lòng hoàn thành đơn hàng trước đó!',
    //         ], 422);
    //     }

    //     $gemPrice = GemPrice::with('server')
    //         ->where('server_id', $validated['server_id'])
    //         ->where('status', true)
    //         ->latest()
    //         ->first();

    //     if (!$gemPrice) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Không tìm thấy giá ngọc cho server này!',
    //         ], 422);
    //     }

    //     $server = $gemPrice->server;

    //     if (!$server || !$server->status) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Server đang tạm ngưng. Không thể tạo đơn.',
    //         ], 422);
    //     }

    //     // ✅ Lấy multiplier và tính ngọc theo công thức mới
    //     $multiplier = $gemPrice->multiplier;
    //     $moneyAmount = $validated['money_amount'];

    //     // ✅ Công thức ngọc: (money_amount / 10000) × multiplier × 10
    //     // Sử dụng BCMath để tính chính xác
    //     $baseAmount = bcdiv((string)$moneyAmount, '10000', 8); // Chia cho 10,000
    //     $multipliedAmount = bcmul($baseAmount, (string)$multiplier, 8); // Nhân với multiplier
    //     $gemQtyTotal = bcmul($multipliedAmount, '10', 0); // Nhân với 10 và làm tròn

    //     // ✅ Đảm bảo gem_qty là số nguyên dương
    //     // $gemQtyTotal = max(1, (int)$gemQtyTotal);
    //     $gemQtyTotal = (int) ceil((float) bcmul($multipliedAmount, '10', 8));

    //     // Kiểm tra balance
    //     if ($user->balance < $moneyAmount) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Số dư không đủ.',
    //         ], 400);
    //     }

    //     if ($user->roles()->exists()) {
    //         return response()->json(['message' => 'Bạn Là Cộng tác viên không được mua nick nhé'], 403);
    //     }

    //     DB::beginTransaction();
    //     try {
    //         // Trừ tiền user
    //         $affected = User::where('id', $user->id)
    //             ->where('balance', '>=', $moneyAmount)
    //             ->decrement('balance', $moneyAmount);

    //         // $balanceAfter = $user->fresh()->balance;

    //         if (!$affected) {
    //             DB::rollBack();
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Số dư không đủ.',
    //             ], 400);
    //         }

    //         // ✅ Tạo gem transaction
    //         $order = GemTransaction::create([
    //             'user_id'            => $user->id,
    //             'server_id'          => $validated['server_id'],
    //             'character_name'     => $characterName,
    //             'amount_vnd'         => $moneyAmount,
    //             'gem_qty'           => $gemQtyTotal, // ✅ Số ngọc tính được
    //             'price_at_transaction' => $multiplier, // ✅ Lưu multiplier tại thời điểm giao dịch
    //             'status'             => 'pending',
    //             'updated_by'         => 'web',
    //         ]);

    //         TransactionService::log(
    //             userId: $user->id,
    //             type: 'mua ngọc',
    //             amount: -$moneyAmount,
    //             description: "mua ngọc #{$order->id} - Nhân vật: {$validated['character_name']}",
    //             performedBy: $user->id ?? null,
    //             related: GemTransaction::class
    //         );
    //         DB::commit();

    //         // ✅ Return response với thông tin ngọc
    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Đặt mua ngọc thành công.',
    //             'data'    => [
    //                 'id'                    => $order->id,
    //                 'server_id'            => $order->server_id,
    //                 'character_name'       => $order->character_name,
    //                 'amount_vnd'           => $order->amount_vnd,
    //                 'gem_qty'             => $order->gem_qty,
    //                 'multiplier'          => $order->price_at_transaction,
    //                 'status'              => $order->status,
    //                 'created_at'          => $order->created_at,
    //                 'server_name'         => $server->name_view ?? $server->name,
    //             ],
    //         ]);
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Có lỗi xảy ra: ' . $e->getMessage(),
    //         ], 500);
    //     }
    // }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'server_id' => 'required|exists:servers,id',
            'character_name' => 'required|string|min:3|max:191',
            'money_amount' => 'required|integer|min:3000',
        ]);

        $user = $request->user();
        $characterName = strtolower(str_replace(' ', '', $validated['character_name']));

        // Check pending orders
        // $hasPendingOrder = GemTransaction::where('user_id', $user->id)
        //     ->whereIn('status', ['pending', 'processing'])
        //     ->exists();
        // if ($hasPendingOrder) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Vui lòng hoàn thành đơn hàng trước đó!',
        //     ], 422);
        // }
        $characterName = strtolower(str_replace(' ', '', $validated['character_name']));

        $hasPendingOrder = GemTransaction::where('user_id', $user->id)
            ->where('server_id', $validated['server_id'])
            ->whereRaw('LOWER(REPLACE(character_name, " ", "")) = ?', [$characterName])
            ->whereIn('status', [
                GemTransaction::STATUS_PENDING,
                GemTransaction::STATUS_PROCESSING,
            ])
            ->exists();

        if ($hasPendingOrder) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng hoàn thành đơn hàng trước đó cho nhân vật này!',
            ], 422);
        }

        //  Get GemPrice
        $gemPrice = GemPrice::with('server')
            ->where('server_id', $validated['server_id'])
            ->where('status', true)
            ->latest()
            ->first();

        if (! $gemPrice) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy giá ngọc cho server này!',
            ], 422);
        }

        $server = $gemPrice->server;
        if (! $server || ! $server->status) {
            return response()->json([
                'success' => false,
                'message' => 'Server đang tạm ngưng. Không thể tạo đơn.',
            ], 422);
        }

        // ✅ Tính ngọc
        $multiplier = $gemPrice->multiplier;
        $moneyAmount = $validated['money_amount'];

        $baseAmount = bcdiv((string) $moneyAmount, '10000', 8);
        $multipliedAmount = bcmul($baseAmount, (string) $multiplier, 8);
        $gemQtyTotal = (int) ceil((float) bcmul($multipliedAmount, '10', 8));

        // ✅ Giới hạn 20,000 ngọc/lần
        if ($gemQtyTotal > 20000) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn chỉ được mua tối đa 20,000 ngọc trong một lần giao dịch.',
            ], 422);
        }

        // ✅ Lấy bot có nhiều ngọc nhất trong server
        // $bot = GemBot::getBotWithMostGems($validated['server_id']);

        // if (!$bot) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Không có bot nào hoạt động trong server này!',
        //     ], 422);
        // }

        // // ✅ Nếu bot không đủ ngọc
        // if ($bot->gem_qty < $gemQtyTotal) {
        //     // Tính lại số tiền tương ứng với số ngọc hiện có của bot
        //     $availableGem = $bot->gem_qty;
        //     $moneyEnough = (int) ceil(($availableGem / ($multiplier * 10)) * 10000);

        //     return response()->json([
        //         'success' => false,
        //         'message' => "Bot hiện chỉ còn {$availableGem} ngọc. Bạn chỉ có thể mua tối đa {$moneyEnough} VNĐ (~{$availableGem} ngọc).
        //         hãy mua nốt để tự động đổi bot",
        //         'available_gem' => $availableGem,
        //         'max_money' => $moneyEnough,
        //     ], 422);
        // }

        // ✅ Kiểm tra balance
        if ($user->balance < $moneyAmount) {
            return response()->json([
                'success' => false,
                'message' => 'Số dư không đủ.',
            ], 400);
        }

        if ($user->roles()->exists()) {
            return response()->json(['message' => 'Bạn Là Cộng tác viên không được mua nhé'], 403);
        }

        DB::beginTransaction();
        try {
            // ✅ Trừ tiền user
            $affected = User::where('id', $user->id)
                ->where('balance', '>=', $moneyAmount)
                ->decrement('balance', $moneyAmount);

            if (! $affected) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Số dư không đủ.',
                ], 400);
            }

            $balanceAfter = (int) User::query()->whereKey($user->id)->value('balance');
            $balanceBefore = $balanceAfter + (int) $moneyAmount;

            // ✅ Tạo gem transaction
            $order = GemTransaction::create([
                'user_id' => $user->id,
                'server_id' => $validated['server_id'],
                'character_name' => $characterName,
                'amount_vnd' => $moneyAmount,
                'gem_qty' => $gemQtyTotal,
                'price_at_transaction' => $multiplier,
                'status' => 'pending',
                'updated_by' => 'web',
            ]);

            // ✅ Ghi log
            TransactionService::log(
                userId: $user->id,
                type: 'mua ngọc',
                amount: -$moneyAmount,
                description: "mua ngọc #{$order->id} - Nhân vật: {$validated['character_name']}",
                performedBy: $user->id,
                related: $order,
                relatedId: $order->id,
                oldBalance: $balanceBefore,
                newBalance: $balanceAfter,
                idempotencyKey: "gem-order-purchase:{$order->id}:user:{$user->id}",
                metadata: [
                    'source' => 'api',
                    'server_id' => $order->server_id,
                    'character_name' => $order->character_name,
                    'gem_qty' => (int) $order->gem_qty,
                ],
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Đặt mua ngọc thành công.',
                'data' => [
                    'id' => $order->id,
                    'server_id' => $order->server_id,
                    'character_name' => $order->character_name,
                    'amount_vnd' => $order->amount_vnd,
                    'gem_qty' => $order->gem_qty,
                    'multiplier' => $order->price_at_transaction,
                    'status' => $order->status,
                    'created_at' => $order->created_at,
                    'server_name' => $server->name_view ?? $server->name,
                    // 'bot_name'       => $bot->name,
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Không thể tạo đơn mua ngọc lúc này. Vui lòng thử lại.',
            ], 500);
        }
    }
}
