<?php

// app/Http/Controllers/Admin/GemBotController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\GemBots\GemBotResource;
use App\Models\GemBot;
use App\Models\Server;
use App\Models\ServerGameLogin;
use App\Services\BotHistoryService;
use App\Services\InventoryMovementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class GemBotController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = GemBot::with('server');

        if ($request->filled('server_id')) {
            $query->where('server_id', $request->server_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('account_name', 'LIKE', '%' . $request->search . '%')
                    ->orWhere('name', 'LIKE', '%' . $request->search . '%');
            });
        }

        if ($request->filled('min_gems')) {
            $query->where('gem_qty', '>=', $request->min_gems);
        }

        if ($request->filled('max_gems')) {
            $query->where('gem_qty', '<=', $request->max_gems);
        }

        // ✅ Sắp xếp bot đang hoạt động lên trước + gem nhiều lên trước
        $query->orderByDesc('status')
            ->orderByDesc('gem_qty')
            ->orderByDesc('id'); // optional

        $gemBots = $query->paginate(20)->withQueryString();

        return Inertia::render('Admin/GemBots/Index', [
            'gemBots' => GemBotResource::collection($gemBots),
            'servers' => Server::active()->get(['id', 'name', 'name_view']),
            'logins' => ServerGameLogin::get(['id', 'name']),
            'filters' => $request->only(['server_id', 'search', 'status', 'min_gems', 'max_gems']),
            'stats' => [
                'total_bots' => GemBot::count(),
                'active_bots' => GemBot::where('status', true)->count(),
                'total_gems' => GemBot::where('status', true)->sum('gem_qty'),
            ]
        ]);
    }


    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Admin/GemBots/Create', [
            'servers' => Server::active()->get(['id', 'name']),
            'logins' => ServerGameLogin::get(['id', 'name']),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'account_name' => 'required|string|max:255|unique:gem_bots,account_name',
            'account_password' => 'required|string|max:255',
            'server_id' => 'required|exists:servers,id',
            'server_game_id' => 'required|exists:server_game_login,id',
            'gem_qty' => 'nullable|integer|min:0',
            'map_name' => 'nullable|string|max:255',
            'map_id' => 'required|string|max:255',
            'area_number' => 'required|string|max:255',
            'coordinates' => 'required|string|max:150',
            'proxy' => 'nullable|string|max:150',
            'status' => 'required|boolean',
        ]);

        $gemBot = DB::transaction(function () use ($validated) {
            $gemBot = GemBot::create([
            'name' => $validated['name'] ?? null,
            'account_name' => $validated['account_name'],
            'account_password' => $validated['account_password'],
            'server_id' => $validated['server_id'],
            'server_game_id' => $validated['server_game_id'],
            'gem_qty' => $validated['gem_qty'] ?? 0,
            'map_name' => $validated['map_name'],
            'map_id' => $validated['map_id'],
            'area_number' => $validated['area_number'],
            'coordinates' => $validated['coordinates'],
            'proxy' => $validated['proxy'],
            'status' => $validated['status'],
            'updated_by' => 'web',
            ]);

            InventoryMovementService::recordGemChange(
                bot: $gemBot,
                before: 0,
                after: (int) $gemBot->gem_qty,
                movementType: 'opening_balance',
                source: 'web',
                idempotencyKey: "opening:gem_bot:{$gemBot->id}",
                adminUserId: auth()->id(),
                note: 'Initial gems from newly created bot'
            );

            return $gemBot;
        });

        return Redirect::back()
            ->with('success', 'Gem Bot đã được tạo thành công.');
    }

    /**
     * Display the specified resource.
     */
    public function show(GemBot $gemBot)
    {
        $gemBot->load('server');

        // Get recent transactions related to this bot
        $recentActivity = [
            'last_sync' => $gemBot->last_synced_at,
            'updated_by' => $gemBot->updated_by,
            'total_gems_sold_today' => 0, // You can implement this logic
        ];

        return Inertia::render('Admin/GemBots/Show', [
            'gemBot' => new GemBotResource($gemBot),
            'recentActivity' => $recentActivity,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(GemBot $gemBot)
    {
        return Inertia::render('Admin/GemBots/Edit', [
            'gemBot' => new GemBotResource($gemBot),
            'servers' => Server::active()->get(['id', 'name']),
            'logins' => ServerGameLogin::get(['id', 'name']),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    // public function update(Request $request, GemBot $gemBot)
    // {
    //     $validated = $request->validate([
    //         'name' => 'nullable|string|max:255',
    //         'account_name' => 'required|string|max:255|unique:gem_bots,account_name,' . $gemBot->id,
    //         'account_password' => 'nullable|string|max:255',
    //         'server_id' => 'required|exists:servers,id',
    //         'server_game_id' => 'required|exists:server_game_login,id',
    //         'gem_qty' => 'nullable|integer|min:0',
    //         'map_name' => 'nullable|string|max:255',
    //         'map_id' => 'required|string|max:255',
    //         'area_number' => 'required|string|max:255',
    //         'coordinates' => 'required|string|max:255',
    //         'proxy' => 'nullable|string|max:150',
    //         'status' => 'required|boolean',
    //     ]);

    //     $updateData = [
    //         'name' => $validated['name'],
    //         'account_name' => $validated['account_name'],
    //         'server_id' => $validated['server_id'],
    //         'server_game_id' => $validated['server_game_id'],
    //         'gem_qty' => $validated['gem_qty'] ?? $gemBot->gem_qty,
    //         'map_name' => $validated['map_name'],
    //         'map_id' => $validated['map_id'],
    //         'area_number' => $validated['area_number'],
    //         'coordinates' => $validated['coordinates'],
    //         'proxy' => $validated['proxy'],
    //         'status' => $validated['status'],
    //         'updated_by' => 'web',
    //     ];

    //     // Only update password if provided
    //     if ($request->filled('account_password')) {
    //         $updateData['account_password'] = $validated['account_password'];
    //     }

    //     $gemBot->update($updateData);

    //     return Redirect::back()
    //         ->with('success', 'Gem Bot đã được cập nhật thành công.');
    // }

    public function update(Request $request, GemBot $gemBot)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'account_name' => 'required|string|max:255|unique:gem_bots,account_name,' . $gemBot->id,
            'account_password' => 'nullable|string|max:255',
            'server_id' => 'required|exists:servers,id',
            'server_game_id' => 'required|exists:server_game_login,id',
            'gem_qty' => 'nullable|integer|min:0',
            'map_name' => 'nullable|string|max:255',
            'map_id' => 'required|string|max:255',
            'area_number' => 'required|string|max:255',
            'coordinates' => 'required|string|max:255',
            'proxy' => 'nullable|string|max:150',
            'status' => 'required|boolean',
        ]);

        $updateData = [
            'name' => $validated['name'] ?? $gemBot->name,
            'account_name' => $validated['account_name'],
            'server_id' => $validated['server_id'],
            'server_game_id' => $validated['server_game_id'],
            'gem_qty' => $validated['gem_qty'] ?? $gemBot->gem_qty,
            'map_name' => $validated['map_name'] ?? $gemBot->map_name,
            'map_id' => $validated['map_id'],
            'area_number' => $validated['area_number'],
            'coordinates' => $validated['coordinates'],
            'proxy' => $validated['proxy'] ?? $gemBot->proxy,
            'status' => $validated['status'],
            'updated_by' => 'web',
        ];

        if ($request->filled('account_password')) {
            $updateData['account_password'] = $validated['account_password'];
        }

        DB::transaction(function () use ($gemBot, $updateData) {
            $oldServerId = (int) $gemBot->server_id;
            $oldGemQty = (int) $gemBot->gem_qty;
            $oldData = $gemBot->only([
                'name',
                'account_name',
                'account_password',
                'server_id',
                'server_game_id',
                'gem_qty',
                'map_name',
                'map_id',
                'area_number',
                'coordinates',
                'proxy',
                'status',
                'updated_by',
                'last_synced_at',
            ]);

            $gemBot->update($updateData);
            $gemBot->refresh();

            $newData = $gemBot->only([
                'name',
                'account_name',
                'account_password',
                'server_id',
                'server_game_id',
                'gem_qty',
                'map_name',
                'map_id',
                'area_number',
                'coordinates',
                'proxy',
                'status',
                'updated_by',
                'last_synced_at',
            ]);

            if ($oldServerId !== (int) $gemBot->server_id) {
                InventoryMovementService::recordGemChange(
                    bot: $gemBot,
                    before: $oldGemQty,
                    after: 0,
                    movementType: 'transfer_out',
                    source: 'web',
                    adminUserId: auth()->id(),
                    note: 'Gem bot moved to another server',
                    serverId: $oldServerId
                );
                InventoryMovementService::recordGemChange(
                    bot: $gemBot,
                    before: 0,
                    after: (int) $gemBot->gem_qty,
                    movementType: 'transfer_in',
                    source: 'web',
                    adminUserId: auth()->id(),
                    note: 'Gem bot moved from another server'
                );
            } else {
                InventoryMovementService::recordGemChange(
                    bot: $gemBot,
                    before: $oldGemQty,
                    after: (int) $gemBot->gem_qty,
                    movementType: 'manual_adjustment',
                    source: 'web',
                    adminUserId: auth()->id(),
                    note: 'Admin changed gem bot balance'
                );
            }

            BotHistoryService::logUpdate(
                entityType: 'gem_bot',
                model: $gemBot,
                oldData: $oldData,
                newData: $newData,
                source: 'web',
                category: 'config',
                adminUserId: auth()->id(),
                transactionId: null,
                transactionType: null,
                note: 'Admin updated gem bot manually'
            );
        });

        return Redirect::back()
            ->with('success', 'Gem Bot đã được cập nhật thành công.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(GemBot $gemBot)
    {
        // Check if bot has gems before deleting
        if ($gemBot->gem_qty > 0) {
            return Redirect::back()
                ->with('error', 'Không thể xóa bot còn ngọc. Vui lòng chuyển ngọc trước.');
        }

        $gemBot->delete();

        return Redirect::back()
            ->with('success', 'Gem Bot đã được xóa thành công.');
    }

    /**
     * Bulk update gem quantities (for syncing from app)
     */
    public function syncGems(Request $request)
    {
        $validated = $request->validate([
            'updates' => 'required|array',
            'updates.*.id' => 'required|exists:gem_bots,id',
            'updates.*.gem_qty' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($validated) {
            foreach ($validated['updates'] as $update) {
                $bot = GemBot::query()->whereKey($update['id'])->lockForUpdate()->firstOrFail();
                $before = (int) $bot->gem_qty;
                $bot->syncFromApp($update['gem_qty']);

                InventoryMovementService::recordGemChange(
                    bot: $bot,
                    before: $before,
                    after: (int) $bot->gem_qty,
                    movementType: 'sync_correction',
                    source: 'web',
                    adminUserId: auth()->id(),
                    note: 'Bulk gem balance synchronization'
                );
            }
        });

        return response()->json([
            'message' => 'Đã đồng bộ số lượng ngọc thành công',
            'updated_count' => count($validated['updates'])
        ]);
    }

    /**
     * Update gem quantity for a single bot
     */
    public function updateGemQuantity(Request $request, GemBot $gemBot)
    {
        $validated = $request->validate([
            'gem_qty' => 'required|integer|min:0',
            'operation' => 'nullable|in:set,add,subtract',
        ]);

        DB::transaction(function () use ($gemBot, $validated) {
            $before = (int) $gemBot->gem_qty;
            $operation = $validated['operation'] ?? 'set';

            switch ($operation) {
                case 'add':
                    $gemBot->updateGemQuantity($validated['gem_qty'], 'add');
                    break;
                case 'subtract':
                    $gemBot->updateGemQuantity($validated['gem_qty'], 'subtract');
                    break;
                default:
                    $gemBot->update(['gem_qty' => $validated['gem_qty'], 'updated_by' => 'web']);
            }

            InventoryMovementService::recordGemChange(
                bot: $gemBot,
                before: $before,
                after: (int) $gemBot->fresh()->gem_qty,
                movementType: 'manual_adjustment',
                source: 'web',
                adminUserId: auth()->id(),
                note: "Admin gem quantity operation: {$operation}"
            );
        });

        return response()->json([
            'message' => 'Cập nhật số lượng ngọc thành công',
            'gem_qty' => $gemBot->fresh()->gem_qty
        ]);
    }

    /**
     * Toggle bot status
     */
    public function toggleStatus(GemBot $gemBot)
    {
        $gemBot->update([
            'status' => !$gemBot->status,
            'updated_by' => 'web'
        ]);

        $status = $gemBot->status ? 'kích hoạt' : 'vô hiệu hóa';

        return Redirect::back()
            ->with('success', "Gem Bot đã được {$status}.");
    }
}
