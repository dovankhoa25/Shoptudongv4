<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\GoldPrices\GoldPriceResource;
use App\Models\GoldPrice;
use App\Models\Bot;
use App\Models\GoldTradingPolicy;
use App\Models\Server;
use App\Services\GoldTradingPolicyService;
use App\Support\AdminTableSearch;
use App\Support\ApiCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class GoldPriceController extends Controller
{
    public function index(Request $request)
    {
        $query = GoldPrice::with('server');

        if ($request->filled('server_id')) {
            $query->where('server_id', $request->server_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        AdminTableSearch::applyPreset($query, $request->input('search'), 'prices');
        $prices = $query->latest()->paginate(20)->withQueryString();

        return Inertia::render('Admin/GoldPrices/Index', [
            'prices' => GoldPriceResource::collection($prices),
            'servers' => Server::all(['id', 'name']),
            'tradingPolicies' => GoldTradingPolicy::all(),
            'tradingAssets' => collect(GoldTradingPolicyService::ASSETS)
                ->map(fn ($asset, $type) => ['asset_type' => $type, ...$asset])->values(),
            'botStocks' => Bot::query()->select('server_id')
                ->selectRaw('SUM(gold_qty) AS pure, SUM(gold_bar_qty) AS standard, SUM(nrj_gold_bar_qty) AS nrj')
                ->groupBy('server_id')->get(),
            'filters' => $request->only(['server_id', 'status', 'search']),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'server_id' => 'required|exists:servers,id',
            'price' => 'required|numeric|min:0',
            'import_price' => 'required|numeric|min:0',
            'status' => 'required|boolean',
        ]);

        GoldPrice::create([
            'server_id' => $request->server_id,
            'price' => $request->price,
            'import_price' => $request->import_price,
            'status' => $request->status,
        ]);
        ApiCache::clearGroups(['public:server-prices']);

        return Redirect::route('admin.gold-prices.index')->with('success', 'Giá vàng đã được tạo.');
    }

    public function updateTradingPolicy(Request $request, Server $server)
    {
        $validated = $request->validate([
            'policies' => ['required', 'array', 'size:3'],
            'policies.*' => ['required', 'array:asset_type,sell_enabled,import_enabled,import_stock_limit_bars'],
            'policies.*.asset_type' => ['required', 'string', 'distinct', Rule::in(array_keys(GoldTradingPolicyService::ASSETS))],
            'policies.*.sell_enabled' => ['required', 'boolean'],
            'policies.*.import_enabled' => ['required', 'boolean'],
            'policies.*.import_stock_limit_bars' => ['present', 'nullable', 'integer', 'min:0', 'max:9000000000000000'],
        ]);

        DB::transaction(function () use ($server, $validated): void {
            foreach ($validated['policies'] as $policy) {
                GoldTradingPolicy::updateOrCreate(
                    ['server_id' => $server->id, 'asset_type' => $policy['asset_type']],
                    ['sell_enabled' => $policy['sell_enabled'], 'import_enabled' => $policy['import_enabled'],
                        'import_stock_limit_bars' => $policy['asset_type'] === 'pure' ? null : $policy['import_stock_limit_bars']],
                );
            }
        });

        return back()->with('success', 'Đã lưu cấu hình nhập/bán vàng của server.');
    }

    public function update(Request $request, GoldPrice $goldprice)
    {
        $request->validate([
            'server_id' => 'required|exists:servers,id',
            'price' => 'required|numeric|min:0',
            'import_price' => 'required|numeric|min:0',
            'status' => 'required|boolean',
        ]);

        $goldprice->update([
            'server_id' => $request->server_id,
            'price' => $request->price,
            'import_price' => $request->import_price,
            'status' => $request->status,
        ]);
        ApiCache::clearGroups(['public:server-prices']);

        return Redirect::route('admin.gold-prices.index')->with('success', 'Giá vàng đã được cập nhật.');
    }

    public function destroy(GoldPrice $goldPrice)
    {
        $goldPrice->delete();
        ApiCache::clearGroups(['public:server-prices']);

        return Redirect::route('admin.gold-prices.index')->with('success', 'Giá vàng đã được xoá.');
    }
}
