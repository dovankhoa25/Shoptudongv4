<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\AccountEncrypt;
use App\Http\Controllers\Controller;
use App\Http\Resources\Bot\BotResource;
use App\Models\Bot;
use App\Models\Server;
use App\Models\ServerGameLogin;
use App\Services\BotHistoryService;
use App\Services\InventoryMovementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class BotController extends Controller
{
    public function index(Request $request)
    {
        $query = Bot::with('server');

        // Filter by server
        if ($request->filled('server_id')) {
            $query->where('server_id', $request->server_id);
        }

        // Filter by bot type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Search by account name or name
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('account_name', 'LIKE', '%' . $request->search . '%')
                    ->orWhere('name', 'LIKE', '%' . $request->search . '%');
            });
        }

        // Filter by gold quantity range
        if ($request->filled('min_gold')) {
            $query->where('gold_qty', '>=', $request->min_gold);
        }

        if ($request->filled('max_gold')) {
            $query->where('gold_qty', '<=', $request->max_gold);
        }

        $query->orderByDesc('status')
            ->orderByDesc('gold_qty')
            ->orderByDesc('id'); // optional


        $bots = $query->paginate(20)->withQueryString();

        // Get statistics
        $stats = [
            'total_bots' => Bot::count(),
            'active_bots' => Bot::where('status', true)->count(),
            'selling_bots' => Bot::whereIn('type', ['selling_main', 'selling_sub'])->count(),
            'import_bots' => Bot::whereIn('type', ['import_main', 'import_sub'])->count(),
            'total_gold' => Bot::where('status', true)->sum('gold_qty'),
            'total_gold_bars' => Bot::where('status', true)->sum('gold_bar_qty'),
        ];

        return Inertia::render('Admin/Bots/Index', [
            'bots' => BotResource::collection($bots),
            'servers' => Server::active()->get(['id', 'name', 'name_view']),
            'logins' => ServerGameLogin::get(['id', 'name']),
            'filters' => $request->only(['server_id', 'type', 'search', 'status', 'min_gold', 'max_gold', 'sort_by', 'sort_order']),
            'stats' => $stats
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'nullable|string|max:255',
            'account_name' => 'required|string|max:255',
            'account_password' => 'required|string|max:255',
            'type' => 'required|string|max:255',
            'server_id' => 'required|exists:servers,id',
            'server_game_id' => 'required|exists:server_game_login,id',
            'gold_bar_qty' => 'nullable|integer|min:0',
            'gold_qty' => 'nullable|integer|min:0',
            'map_name' => 'nullable|string|max:255',
            'map_id' => 'required|string|max:255',
            'area_number' => 'required|string|max:255',
            'coordinates' => 'required|string|max:255',
            'proxy' => 'nullable|string|max:150',
            'status' => 'required|boolean',
        ]);

        $encryptedPassword = AccountEncrypt::encrypt($request->account_password);

        $bot = DB::transaction(function () use ($request) {
            $bot = Bot::create([
            'name' => $request->name ?? null,
            'account_name' => $request->account_name,
            'account_password' => $request->account_password,
            'type' => $request->type,
            'server_id' => $request->server_id,
            'server_game_id' => $request->server_game_id,
            'gold_bar_qty' => $request->gold_bar_qty ?? 0,
            'gold_qty' => $request->gold_qty ?? 0,
            'map_name' => $request->map_name,
            'map_id' => $request->map_id,
            'area_number' => $request->area_number,
            'coordinates' => $request->coordinates,
            'proxy' => $request->proxy,
            'status' => $request->status,
            'updated_by' => 'web',
            ]);

            InventoryMovementService::recordGoldChange(
                bot: $bot,
                beforeGold: 0,
                beforeBars: 0,
                afterGold: (int) $bot->gold_qty,
                afterBars: (int) $bot->gold_bar_qty,
                movementType: 'opening_balance',
                source: 'web',
                idempotencyKey: "opening:bot:{$bot->id}",
                adminUserId: auth()->id(),
                note: 'Initial converted gold from newly created bot'
            );

            return $bot;
        });

        return Redirect::route('admin.bots.index')->with('success', 'Bot đã được tạo thành công!');
    }

    // public function update(Request $request, Bot $bot)
    // {
    //     $request->validate([
    //         'name' => 'nullable|string|max:255',
    //         'account_name' => 'required|string|max:255',
    //         'account_password' => 'nullable|string|max:255',
    //         'type' => 'required|string|max:255',
    //         'server_id' => 'required|exists:servers,id',
    //         'server_game_id' => 'required|exists:server_game_login,id',
    //         'gold_bar_qty' => 'nullable|integer|min:0',
    //         'gold_qty' => 'nullable|integer|min:0',
    //         'map_name' => 'nullable|string|max:255',
    //         'map_id' => 'required|string|max:255',
    //         'area_number' => 'required|string|max:255',
    //         'coordinates' => 'required|string|max:255',
    //         'proxy' => 'nullable|string|max:255',
    //         'status' => 'required|boolean',
    //     ]);

    //     $bot->update([
    //         'name' => $request->name,
    //         'account_name' => $request->account_name,
    //         'account_password' => $request->account_password,
    //         'type' => $request->type,
    //         'server_id' => $request->server_id,
    //         'server_game_id' => $request->server_game_id,
    //         'gold_bar_qty' => $request->gold_bar_qty ?? 0,
    //         'gold_qty' => $request->gold_qty ?? 0,
    //         'map_name' => $request->map_name,
    //         'map_id' => $request->map_id,
    //         'area_number' => $request->area_number,
    //         'coordinates' => $request->coordinates,
    //         'proxy' => $request->proxy,
    //         'status' => $request->status,
    //         'updated_by' => 'web',
    //     ]);

    //     // return Redirect::route('admin.bots.index')->with('success', 'Bot đã được cập nhật thành công!');
    //     // return back()->with('success', 'Bot đã được cập nhật thành công!');
    //     return redirect()->back()->with('success', 'Bot đã được cập nhật thành công!');
    // }


    public function update(Request $request, Bot $bot)
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'account_name' => 'required|string|max:255',
            'account_password' => 'nullable|string|max:255',
            'type' => 'required|string|max:255',
            'server_id' => 'required|exists:servers,id',
            'server_game_id' => 'required|exists:server_game_login,id',
            'gold_bar_qty' => 'nullable|integer|min:0',
            'gold_qty' => 'nullable|integer|min:0',
            'map_name' => 'nullable|string|max:255',
            'map_id' => 'required|string|max:255',
            'area_number' => 'required|string|max:255',
            'coordinates' => 'required|string|max:255',
            'proxy' => 'nullable|string|max:255',
            'status' => 'required|boolean',
        ]);

        $updateData = [
            'name' => $validated['name'] ?? $bot->name,
            'account_name' => $validated['account_name'],
            'type' => $validated['type'],
            'server_id' => $validated['server_id'],
            'server_game_id' => $validated['server_game_id'],
            'gold_bar_qty' => $validated['gold_bar_qty'] ?? $bot->gold_bar_qty,
            'gold_qty' => $validated['gold_qty'] ?? $bot->gold_qty,
            'map_name' => $validated['map_name'] ?? $bot->map_name,
            'map_id' => $validated['map_id'],
            'area_number' => $validated['area_number'],
            'coordinates' => $validated['coordinates'],
            'proxy' => $validated['proxy'] ?? $bot->proxy,
            'status' => $validated['status'],
            'updated_by' => 'web',
        ];

        if ($request->filled('account_password')) {
            $updateData['account_password'] = $validated['account_password'];
        }

        DB::transaction(function () use ($bot, $updateData) {
            $oldServerId = (int) $bot->server_id;
            $oldGold = (int) $bot->gold_qty;
            $oldBars = (int) $bot->gold_bar_qty;
            $oldData = $bot->only([
                'name',
                'account_name',
                'account_password',
                'type',
                'server_id',
                'server_game_id',
                'gold_bar_qty',
                'gold_qty',
                'map_name',
                'map_id',
                'area_number',
                'coordinates',
                'proxy',
                'status',
                'updated_by',
                'last_synced_at',
            ]);

            $bot->update($updateData);
            $bot->refresh();

            $newData = $bot->only([
                'name',
                'account_name',
                'account_password',
                'type',
                'server_id',
                'server_game_id',
                'gold_bar_qty',
                'gold_qty',
                'map_name',
                'map_id',
                'area_number',
                'coordinates',
                'proxy',
                'status',
                'updated_by',
                'last_synced_at',
            ]);

            if ($oldServerId !== (int) $bot->server_id) {
                InventoryMovementService::recordGoldChange(
                    bot: $bot,
                    beforeGold: $oldGold,
                    beforeBars: $oldBars,
                    afterGold: 0,
                    afterBars: 0,
                    movementType: 'transfer_out',
                    source: 'web',
                    adminUserId: auth()->id(),
                    note: 'Gold bot moved to another server',
                    serverId: $oldServerId
                );
                InventoryMovementService::recordGoldChange(
                    bot: $bot,
                    beforeGold: 0,
                    beforeBars: 0,
                    afterGold: (int) $bot->gold_qty,
                    afterBars: (int) $bot->gold_bar_qty,
                    movementType: 'transfer_in',
                    source: 'web',
                    adminUserId: auth()->id(),
                    note: 'Gold bot moved from another server'
                );
            } else {
                InventoryMovementService::recordGoldChange(
                    bot: $bot,
                    beforeGold: $oldGold,
                    beforeBars: $oldBars,
                    afterGold: (int) $bot->gold_qty,
                    afterBars: (int) $bot->gold_bar_qty,
                    movementType: 'manual_adjustment',
                    source: 'web',
                    adminUserId: auth()->id(),
                    note: 'Admin changed converted gold balance'
                );
            }

            BotHistoryService::logUpdate(
                entityType: 'bot',
                model: $bot,
                oldData: $oldData,
                newData: $newData,
                source: 'web',
                category: 'config',
                adminUserId: auth()->id(),
                transactionId: null,
                transactionType: null,
                note: 'Admin updated bot manually'
            );
        });

        return redirect()->back()->with('success', 'Bot đã được cập nhật thành công!');
    }

    // public function update(Request $request, Bot $bot)
    // {
    //     $request->validate([
    //         'name' => 'nullable|string|max:255',
    //         'account_name' => 'required|string|max:255',
    //         'account_password' => 'nullable|string|max:255',
    //         'type' => 'required|in:selling_main,selling_sub,import_main,import_sub',
    //         'server_id' => 'required|exists:servers,id',
    //         'gold_bar_qty' => 'nullable|integer|min:0',
    //         'gold_qty' => 'nullable|integer|min:0',
    //         'map_name' => 'nullable|string|max:255',
    //         'map_id' => 'required|string|max:255',
    //         'area_number' => 'required|string|max:255',
    //         'status' => 'required|boolean',
    //     ]);

    //     // Chuẩn bị dữ liệu cập nhật
    //     $updateData = [
    //         'name' => $request->name,
    //         'account_name' => $request->account_name,
    //         'type' => $request->type,
    //         'server_id' => $request->server_id,
    //         'gold_bar_qty' => $request->gold_bar_qty ?? 0,
    //         'gold_qty' => $request->gold_qty ?? 0,
    //         'map_name' => $request->map_name,
    //         'map_id' => $request->map_id,
    //         'area_number' => $request->area_number,
    //         'status' => $request->status,
    //         'updated_by' => 'web',
    //     ];

    //     if ($request->filled('account_password')) {
    //         $updateData['account_password'] = $request->account_password;
    //     }

    //     $bot->update($updateData);

    //     return Redirect::route('admin.bots.index')->with('success', 'Bot đã được cập nhật thành công!');
    // }
    /**
     * Toggle bot status
     */
    public function toggleStatus(Request $request, Bot $bot)
    {
        $bot->update([
            'status' => !$bot->status,
            'updated_by' => 'web',
        ]);

        $status = $bot->status ? 'kích hoạt' : 'tạm dừng';
        return back()->with('success', "Bot đã được {$status} thành công!");
    }

    /**
     * Show bot detail
     */
    public function show(Bot $bot)
    {
        $bot->load(['server']);

        return Inertia::render('Admin/Bots/Show', [
            'bot' => new BotResource($bot)
        ]);
    }

    /**
     * Delete bot
     */
    public function destroy(Bot $bot)
    {
        if ((int) $bot->gold_qty > 0 || (int) $bot->gold_bar_qty > 0) {
            return back()->with('error', 'Không thể xóa bot còn vàng. Hãy chuyển hoặc điều chỉnh tài sản về 0 trước.');
        }

        $botName = $bot->name ?? $bot->account_name;
        $bot->delete();

        return back()->with('success', "Bot \"{$botName}\" đã được xóa thành công!");
    }

    /**
     * Bulk activate bots
     */
    public function bulkActivate(Request $request)
    {
        $request->validate([
            'bot_ids' => 'required|array',
            'bot_ids.*' => 'exists:bots,id'
        ]);

        $updated = Bot::whereIn('id', $request->bot_ids)
            ->update([
                'status' => true,
                'updated_by' => 'web',
                'updated_at' => now()
            ]);

        return back()->with('success', "Đã kích hoạt {$updated} bot!");
    }

    /**
     * Bulk deactivate bots
     */
    public function bulkDeactivate(Request $request)
    {
        $request->validate([
            'bot_ids' => 'required|array',
            'bot_ids.*' => 'exists:bots,id'
        ]);

        $updated = Bot::whereIn('id', $request->bot_ids)
            ->update([
                'status' => false,
                'updated_by' => 'web',
                'updated_at' => now()
            ]);

        return back()->with('success', "Đã tạm dừng {$updated} bot!");
    }

    /**
     * Bulk delete bots
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'bot_ids' => 'required|array',
            'bot_ids.*' => 'exists:bots,id'
        ]);

        $deleted = Bot::whereIn('id', $request->bot_ids)->count();
        Bot::whereIn('id', $request->bot_ids)->delete();

        return back()->with('success', "Đã xóa {$deleted} bot!");
    }
}
