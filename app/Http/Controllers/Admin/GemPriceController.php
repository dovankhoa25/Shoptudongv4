<?php

// app/Http/Controllers/Admin/GemPriceController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\GemPrice\GemPriceResource;
use App\Models\GemPrice;
use App\Models\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class GemPriceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = GemPrice::with('server');

        // Filter by server
        if ($request->filled('server_id')) {
            $query->where('server_id', $request->server_id);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by multiplier range
        if ($request->filled('min_multiplier')) {
            $query->where('multiplier', '>=', $request->min_multiplier);
        }

        if ($request->filled('max_multiplier')) {
            $query->where('multiplier', '<=', $request->max_multiplier);
        }

        // Search
        if ($request->filled('search')) {
            $query->whereHas('server', function ($q) use ($request) {
                $q->where('name', 'LIKE', '%' . $request->search . '%');
            });
        }

        $gemPrices = $query->latest()->paginate(20)->withQueryString();

        // Get stats
        $stats = [
            'total_prices' => GemPrice::count(),
            'active_prices' => GemPrice::where('status', true)->count(),
            'average_multiplier' => round(GemPrice::where('status', true)->avg('multiplier') ?? 0, 2),
            'min_multiplier' => GemPrice::where('status', true)->min('multiplier') ?? 0,
            'max_multiplier' => GemPrice::where('status', true)->max('multiplier') ?? 0,
        ];

        return Inertia::render('Admin/GemPrices/Index', [
            'gemPrices' => GemPriceResource::collection($gemPrices),
            'servers' => Server::active()->get(['id', 'name']),
            'filters' => $request->only(['server_id', 'search', 'status', 'min_multiplier', 'max_multiplier']),
            'stats' => $stats
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'server_id' => 'required|exists:servers,id',
            'multiplier' => 'required|numeric|min:1|max:100',
            'status' => 'required|boolean',
        ]);

        // Deactivate old prices for this server if new one is active
        if ($validated['status']) {
            GemPrice::where('server_id', $validated['server_id'])
                ->where('status', true)
                ->update(['status' => false]);
        }

        $gemPrice = GemPrice::create($validated);

        return Redirect::route('admin.gem-prices.index')
            ->with('success', 'Hệ số giá ngọc đã được tạo thành công.');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, GemPrice $gemPrice)
    {
        $validated = $request->validate([
            'server_id' => 'required|exists:servers,id',
            'multiplier' => 'required|numeric|min:1|max:100',
            'status' => 'required|boolean',
        ]);

        // Deactivate other prices for this server if this one is being activated
        if ($validated['status'] && !$gemPrice->status) {
            GemPrice::where('server_id', $validated['server_id'])
                ->where('id', '!=', $gemPrice->id)
                ->where('status', true)
                ->update(['status' => false]);
        }

        $gemPrice->update($validated);

        return Redirect::route('admin.gem-prices.index')
            ->with('success', 'Hệ số giá ngọc đã được cập nhật thành công.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(GemPrice $gemPrice)
    {
        // Check if this is the only active price for the server
        $activeCount = GemPrice::where('server_id', $gemPrice->server_id)
            ->where('status', true)
            ->count();

        if ($activeCount === 1 && $gemPrice->status) {
            return Redirect::back()
                ->with('error', 'Không thể xóa hệ số đang hoạt động duy nhất. Vui lòng tạo hệ số mới trước.');
        }

        $gemPrice->delete();

        return Redirect::route('admin.gem-prices.index')
            ->with('success', 'Hệ số giá ngọc đã được xóa thành công.');
    }

    /**
     * Toggle price status
     */
    public function toggleStatus(GemPrice $gemPrice)
    {
        // If activating, deactivate others for same server
        if (!$gemPrice->status) {
            GemPrice::where('server_id', $gemPrice->server_id)
                ->where('id', '!=', $gemPrice->id)
                ->where('status', true)
                ->update(['status' => false]);
        }

        $gemPrice->update([
            'status' => !$gemPrice->status
        ]);

        $status = $gemPrice->status ? 'kích hoạt' : 'vô hiệu hóa';

        return Redirect::back()
            ->with('success', "Hệ số giá ngọc đã được {$status}.");
    }

    /**
     * Bulk update multipliers
     */
    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'updates' => 'required|array',
            'updates.*.server_id' => 'required|exists:servers,id',
            'updates.*.multiplier' => 'required|numeric|min:1|max:100',
        ]);

        foreach ($validated['updates'] as $update) {
            // Deactivate old prices
            GemPrice::where('server_id', $update['server_id'])
                ->where('status', true)
                ->update(['status' => false]);

            // Create new price
            GemPrice::create([
                'server_id' => $update['server_id'],
                'multiplier' => $update['multiplier'],
                'status' => true
            ]);
        }

        return response()->json([
            'message' => 'Đã cập nhật hệ số giá ngọc thành công',
            'updated_count' => count($validated['updates'])
        ]);
    }

    /**
     * Get current multipliers for all servers
     */
    public function currentMultipliers()
    {
        $multipliers = Server::active()
            ->with(['currentGemPrice'])
            ->get()
            ->map(function ($server) {
                $gemPrice = $server->currentGemPrice;
                return [
                    'server_id' => $server->id,
                    'server_name' => $server->name,
                    'multiplier' => $gemPrice->multiplier ?? null,
                    'multiplier_display' => $gemPrice ? $gemPrice->multiplier_display : 'Chưa cài đặt',
                    'gems_per_10k' => $gemPrice ? $gemPrice->gems_per_base : 0,
                    'last_updated' => $gemPrice->updated_at ?? null,
                ];
            });

        return response()->json($multipliers);
    }

    /**
     * Calculate gems from VND
     */
    public function calculateGems(Request $request)
    {
        $validated = $request->validate([
            'server_id' => 'required|exists:servers,id',
            'vnd_amount' => 'required|numeric|min:10000',
        ]);

        $gemPrice = GemPrice::getCurrentMultiplier($validated['server_id']);

        if (!$gemPrice) {
            return response()->json([
                'error' => 'Server này chưa có cài đặt giá'
            ], 404);
        }

        $gems = $gemPrice->calculateGems($validated['vnd_amount']);

        return response()->json([
            'vnd_amount' => $validated['vnd_amount'],
            'gems' => $gems,
            'multiplier' => $gemPrice->multiplier,
            'multiplier_display' => $gemPrice->multiplier_display,
        ]);
    }
}
