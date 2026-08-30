<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\GoldTransactionResource;
use App\Models\GoldPrice;
use App\Models\GoldTransaction;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImportController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'server_id' => 'required|exists:servers,id',
            'character_name' => 'required|string|min:3|max:191',
            'pure_gold_qty' => 'nullable|integer|min:0|max:900000000',    // Vàng tươi (từ frontend)
            'gold_bar_qty' => 'nullable|integer|min:0|max:999',          // Số thỏi vàng
        ]);

        $user = $request->user();
        $userId = $user->id;
        $characterName = strtolower(str_replace(' ', '', $validated['character_name']));
        // ✅ Kiểm tra đơn hàng import đang pending hoặc processing
        $hasPendingImport = GoldTransaction::where('user_id', $userId)
            ->where('type', 'import')
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if ($hasPendingImport) {
            return response()->json([
                'success' => false,
                'message' => 'Vui lòng hoàn thành đơn hàng trước đó!',
            ], 422);
        }
        // Lấy dữ liệu từ frontend
        // Code mới (đúng)
        $pureGoldQty = $validated['pure_gold_qty'] ?? 0;  // = 100M (từ frontend)
        $goldBarQty = $validated['gold_bar_qty'] ?? 0;    // = 5

        if ((int) $pureGoldQty === 0 && (int) $goldBarQty === 0) {
            throw ValidationException::withMessages([
                'gold_bar_qty' => 'Phải nhập ít nhất một loại vàng.',
                'pure_gold_qty' => 'Phải nhập ít nhất một loại vàng.',
            ]);
        }

        if ((int) $pureGoldQty > 0 && (int) $pureGoldQty < 50000000) {
            throw ValidationException::withMessages([
                'pure_gold_qty' => 'Vàng tươi tối thiểu là 50.000.000.',
            ]);
        }
        $totalGoldQty = bcadd((string) $pureGoldQty, bcmul((string) $goldBarQty, '37000000'));
        // = 100M + (5 × 37M) = 285M ✅

        // Lấy giá nhập mới nhất từ bảng gold_prices
        $goldPrice = GoldPrice::where('server_id', $validated['server_id'])
            ->where('status', true)
            ->latest()
            ->first();

        if (! $goldPrice || (int) $goldPrice->import_price <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'No active gold price found for this server.',
            ], 422);
        }

        $importPrice = $goldPrice->import_price;

        if (! $goldPrice->server || ! $goldPrice->server->status) {
            return response()->json([
                'success' => false,
                'message' => 'Server đang tạm ngưng. Không thể tạo đơn.',
            ], 422);
        }

        // Tính số tiền VND: tổng vàng ÷ import_price
        $amountVnd = bcdiv($totalGoldQty, (string) $importPrice, 0);

        $import = GoldTransaction::create([
            'type' => 'import',
            'user_id' => $userId,
            'server_id' => $validated['server_id'],
            'character_name' => $characterName,
            'amount_vnd' => $amountVnd,
            'gold_qty' => $totalGoldQty,          // Vàng tươi (lưu vào gold_qty)
            'gold_bar_qty' => $goldBarQty,           // Số thỏi
            'pure_gold_qty' => $pureGoldQty,         // Tổng vàng
            'price_at_transaction' => $importPrice,
            'status' => 'pending',
            'bot_id' => null,
            'updated_by' => 'web',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Tạo đơn nhập vàng thành công.',
            'data' => new GoldTransactionResource($import->load('server')),
        ], 201);
    }
}
