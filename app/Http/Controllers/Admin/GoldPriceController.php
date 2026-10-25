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
        $serverRows=Server::with('goldTradingPolicies')->get();
        $allBots=Bot::with('loginServer')->get();

        return Inertia::render('Admin/GoldPrices/Index', [
            'prices' => GoldPriceResource::collection($prices),
            'servers' => $serverRows->map(fn($server) => ['id'=>$server->id,'name'=>$server->name,
                'gold_radar_enabled'=>(bool)$server->gold_radar_enabled,'radar_supported'=>\App\Services\RadarTradingService::supportedServer($server,$allBots->where('server_id',$server->id))]),
            'tradingPolicies' => GoldTradingPolicy::all(),
            'tradingAssets' => collect(GoldTradingPolicyService::ASSETS)
                ->map(fn ($asset, $type) => ['asset_type' => $type, ...$asset])->values(),
            'botStocks' => $allBots->groupBy('server_id')->map(function($bots,$id)use($serverRows){
                $server=$serverRows->firstWhere('id',$id);
                $radar=$server?app(\App\Services\RadarTradingService::class)->summary($server,$bots):[];
                return ['server_id'=>(int)$id,'pure'=>$bots->sum('gold_qty'),'standard'=>$bots->sum('gold_bar_qty'),
                    'nrj'=>$bots->sum('nrj_gold_bar_qty'),'radar'=>$bots->sum('radar_qty'),'radar_room'=>$radar['import_room']??0,
                    'radar_physical_room'=>$radar['physical_import_room']??0,'radar_unknown'=>$radar['unknown_capacity_bots']??0];
            })->values(),
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
            'gold_radar_enabled' => ['sometimes', 'boolean'],
            'policies' => ['required', 'array', 'min:3', 'max:4'],
            'policies.*' => ['required', 'array:asset_type,sell_enabled,import_enabled,import_stock_limit_bars'],
            'policies.*.asset_type' => ['required', 'string', 'distinct', Rule::in(array_keys(GoldTradingPolicyService::ASSETS))],
            'policies.*.sell_enabled' => ['required', 'boolean'],
            'policies.*.import_enabled' => ['required', 'boolean'],
            'policies.*.import_stock_limit_bars' => ['present', 'nullable', 'integer', 'min:0', 'max:9000000000000000'],
        ]);
        if (($validated['gold_radar_enabled'] ?? false) && !\App\Services\RadarTradingService::supportedServer($server)) {
            return back()->withErrors(['gold_radar_enabled'=>'Chế độ rada chỉ dành cho Super 1–3. Hãy cấu hình đúng endpoint/bot của server trước.']);
        }

        DB::transaction(function () use ($server, $validated): void {
            if (array_key_exists('gold_radar_enabled', $validated)) $server->forceFill(['gold_radar_enabled'=>$validated['gold_radar_enabled']])->save();
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
