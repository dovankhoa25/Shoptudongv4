<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\GemTransaction;
use App\Models\GoldTransaction;
use App\Models\Server;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $day = isset($validated['date'])
            ? Carbon::createFromFormat('Y-m-d', $validated['date'])->startOfDay()
            : now()->startOfDay();
        $dayEnd = $day->copy()->endOfDay();
        $ledgerStartedAt = InventoryMovement::min('occurred_at');
        $transactionPeriodStart = $ledgerStartedAt && Carbon::parse($ledgerStartedAt)->greaterThan($day)
            ? Carbon::parse($ledgerStartedAt)
            : $day;

        $serverModels = Server::with([
            'bots',  // ← Lấy tất cả bots
            'gemBots',  // ← Lấy tất cả gem bots
            'goldPrices' => function ($query) {
                $query->where('status', true)->latest();
            },
            'gemPrices' => function ($query) {
                $query->where('status', true)->latest();
            }
        ])
            ->get();

        $dailyMovements = InventoryMovement::query()
            ->whereBetween('occurred_at', [$day, $dayEnd])
            ->get()
            ->groupBy('server_id');

        $laterMovements = InventoryMovement::query()
            ->where('occurred_at', '>', $dayEnd)
            ->get()
            ->groupBy('server_id');

        $goldTransactions = GoldTransaction::query()
            ->where('status', 'completed')
            ->whereBetween('updated_at', [$transactionPeriodStart, $dayEnd])
            ->get()
            ->groupBy('server_id');

        $gemTransactions = GemTransaction::query()
            ->where('status', GemTransaction::STATUS_COMPLETED)
            ->whereBetween('updated_at', [$transactionPeriodStart, $dayEnd])
            ->get()
            ->groupBy('server_id');

        $servers = $serverModels->map(function ($server) use (
            $dailyMovements,
            $laterMovements,
            $goldTransactions,
            $gemTransactions
        ) {
                // ==================== TÍNH TOÁN VÀNG ====================
                $goldBots = $server->bots;
                $totalGoldBar = $goldBots->sum('gold_bar_qty') ?? 0;
                $totalGold = $goldBots->sum('gold_qty') ?? 0;

                // Tính tổng vàng tươi (1 thỏi = 37 triệu vàng tươi)
                $goldPerBar = '37000000';
                $totalGoldInRaw = bcadd(
                    bcmul((string)$totalGoldBar, $goldPerBar, 0),
                    (string)$totalGold,
                    0
                );

                // Lấy giá vàng active mới nhất
                $goldPrice = $server->goldPrices->first(); // Đã được sort latest() trong eager load
                $goldPriceValue = ($goldPrice && $goldPrice->price) ? (float)$goldPrice->price : 0;

                // Tính tiền: Tổng vàng / price
                $totalGoldValue = 0;
                if ($goldPriceValue > 0 && $totalGoldInRaw > 0) {
                    $totalGoldValue = (float)bcdiv($totalGoldInRaw, (string)$goldPriceValue, 2);
                }

                // ==================== TÍNH TOÁN NGỌC ====================
                $gemBots = $server->gemBots;
                $totalGems = $gemBots->sum('gem_qty') ?? 0;

                // ==================== KIỂM KÊ TRONG NGÀY ====================
                $serverMovements = $dailyMovements->get($server->id, collect());
                $serverLaterMovements = $laterMovements->get($server->id, collect());
                $goldMovements = $serverMovements->where('asset_type', 'pure_gold');
                $gemMovements = $serverMovements->where('asset_type', 'gem');
                $laterGoldDelta = (int) $serverLaterMovements
                    ->where('asset_type', 'pure_gold')->sum('quantity_delta');
                $laterGemDelta = (int) $serverLaterMovements
                    ->where('asset_type', 'gem')->sum('quantity_delta');
                $actualGoldDelta = (int) $goldMovements->sum('quantity_delta');
                $actualGemDelta = (int) $gemMovements->sum('quantity_delta');

                $serverGoldTransactions = $goldTransactions->get($server->id, collect());
                $goldOrders = $serverGoldTransactions->where('type', 'order');
                $goldImports = $serverGoldTransactions->where('type', 'import');
                $serverGemTransactions = $gemTransactions->get($server->id, collect());

                $soldGold = (int) $goldOrders->sum('gold_qty');
                $soldGoldBar = (int) $goldOrders->sum('gold_bar_qty');
                $importedGold = (int) $goldImports->sum('gold_qty');
                $importedGoldBar = (int) $goldImports->sum('gold_bar_qty');
                $soldGems = (int) $serverGemTransactions->sum('gem_qty');

                // gold_transactions.gold_qty đã là tổng quy đổi; gold_bar_qty chỉ là cơ cấu hiển thị.
                // bots.gold_qty lại là vàng tươi nên tổng bot vẫn phải cộng thêm số thỏi × 37 triệu.
                $soldGoldConverted = $soldGold;
                $importedGoldConverted = $importedGold;
                $currentGoldConverted = (int) $totalGoldInRaw;
                $closingGoldConverted = $currentGoldConverted - $laterGoldDelta;
                $closingGems = (int) $totalGems - $laterGemDelta;

                $openingGoldConverted = $closingGoldConverted - $actualGoldDelta;
                $openingGems = $closingGems - $actualGemDelta;

                // Các thay đổi không thuộc đơn (thêm/sửa/chuyển bot) là điều chỉnh kho hợp lệ.
                $goldAdjustments = $goldMovements->whereNotIn('movement_type', ['sale', 'import']);
                $gemAdjustments = $gemMovements->where('movement_type', '!=', 'sale');
                $goldAdjustment = (int) $goldAdjustments->sum('quantity_delta');
                $gemAdjustment = (int) $gemAdjustments->sum('quantity_delta');

                $movementDetails = static fn ($movements) => $movements
                    ->map(fn (InventoryMovement $movement) => [
                        'bot_id' => $movement->bot_id,
                        'type' => $movement->movement_type,
                        'delta' => $movement->quantity_delta,
                        'note' => $movement->note,
                        'time' => $movement->occurred_at?->format('H:i:s'),
                    ])->values();

                $expectedGoldConverted = $openingGoldConverted
                    + $importedGoldConverted
                    - $soldGoldConverted
                    + $goldAdjustment;
                $expectedGems = $openingGems - $soldGems + $gemAdjustment;

                $goldDifference = $closingGoldConverted - $expectedGoldConverted;
                $gemDifference = $closingGems - $expectedGems;

                // Lấy gem price active mới nhất
                $gemPriceModel = $server->gemPrices->first(); // Đã được sort latest() trong eager load
                $gemPricePerUnit = 0;
                $totalGemValue = 0;
                $multiplier = 0;
                $multiplierDisplay = 'N/A';
                $gemsPerBase = 0;

                if ($gemPriceModel && $gemPriceModel->status && $gemPriceModel->multiplier > 0) {
                    // Áp dụng công thức từ controller mua ngọc
                    // Ngọc = (Tiền / 10000) * multiplier * 10
                    // => Tiền = Ngọc / (multiplier * 10) * 10000

                    $multiplier = (float)$gemPriceModel->multiplier;
                    $multiplierDisplay = 'x' . number_format($multiplier, 1);
                    $gemsPerBase = $multiplier * 10;

                    if ($totalGems > 0) {
                        // Tính số tiền = (Tổng ngọc / 10) / multiplier * 10000
                        $multiplierStr = (string)$multiplier;
                        $gemsDiv10 = bcdiv((string)$totalGems, '10', 8);
                        $divByMultiplier = bcdiv($gemsDiv10, $multiplierStr, 8);
                        $totalGemValue = (float)bcmul($divByMultiplier, '10000', 2);

                        // Giá 1 ngọc
                        $gemPricePerUnit = (float)bcdiv('10000', bcmul($multiplierStr, '10', 8), 2);
                    }
                }

                return [
                    'server_id' => $server->id,
                    'server_name' => $server->name,

                    // Gold Data
                    'gold' => [
                        'total_gold_bar' => (int)$totalGoldBar,
                        'total_gold' => (int)$totalGold,
                        'total_gold_raw' => (float)$totalGoldInRaw,
                        'price_per_gold' => $goldPriceValue,
                        'total_value' => $totalGoldValue,
                        'bot_count' => $goldBots->count(),
                        'has_price' => $goldPriceValue > 0,
                    ],

                    // Gem Data
                    'gem' => [
                        'total_gems' => (int)$totalGems,
                        'price_per_gem' => $gemPricePerUnit,
                        'multiplier' => $multiplier,
                        'multiplier_display' => $multiplierDisplay,
                        'gems_per_10k' => $gemsPerBase,
                        'total_value' => $totalGemValue,
                        'bot_count' => $gemBots->count(),
                        'has_price' => $multiplier > 0,
                    ],

                    // Total Value
                    'total_value' => $totalGoldValue + $totalGemValue,
                    'reconciliation' => [
                        'gem' => [
                            'opening' => $openingGems,
                            'adjustment' => $gemAdjustment,
                            'adjustment_details' => $movementDetails($gemAdjustments),
                            'sold' => $soldGems,
                            'order_count' => $serverGemTransactions->count(),
                            'expected' => $expectedGems,
                            'actual' => $closingGems,
                            'difference' => $gemDifference,
                            'is_matched' => $gemDifference === 0,
                        ],
                        'gold' => [
                            'opening_converted' => $openingGoldConverted,
                            'sold_converted' => $soldGoldConverted,
                            'imported_converted' => $importedGoldConverted,
                            'adjustment' => $goldAdjustment,
                            'adjustment_details' => $movementDetails($goldAdjustments),
                            'order_count' => $goldOrders->count(),
                            'import_count' => $goldImports->count(),
                            'expected_converted' => $expectedGoldConverted,
                            'actual_converted' => $closingGoldConverted,
                            'difference' => $goldDifference,
                            'is_matched' => $goldDifference === 0,
                        ],
                    ],
                ];
            });

        // Tính tổng toàn bộ
        $grandTotal = [
            'total_gold_value' => $servers->sum('gold.total_value'),
            'total_gem_value' => $servers->sum('gem.total_value'),
            'total_value' => $servers->sum('total_value'),
        ];

        return Inertia::render('Admin/Dashboard', [
            'servers' => $servers,
            'grandTotal' => $grandTotal,
            'reconciliationDate' => $day->toDateString(),
            'ledgerStartedAt' => $ledgerStartedAt,
        ]);
    }
}
