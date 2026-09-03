<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BotHistory\BotHistoryResource;
use App\Models\Bot;
use App\Models\BotHistory;
use App\Models\GemBot;
use App\Models\Server;
use App\Support\AdminTableSearch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BotHistoryController extends Controller
{
    // public function index(Request $request)
    // {
    //     $query = BotHistory::query()
    //         ->with('adminUser:id,username')
    //         ->latest();

    //     if ($request->filled('entity_type')) {
    //         $query->where('entity_type', $request->entity_type);
    //     }

    //     if ($request->filled('entity_id')) {
    //         $query->where('entity_id', $request->entity_id);
    //     }

    //     if ($request->filled('source')) {
    //         $query->where('source', $request->source);
    //     }

    //     if ($request->filled('action')) {
    //         $query->where('action', $request->action);
    //     }

    //     if ($request->filled('date_from')) {
    //         $query->whereDate('created_at', '>=', $request->date_from);
    //     }

    //     if ($request->filled('date_to')) {
    //         $query->whereDate('created_at', '<=', $request->date_to);
    //     }

    //     $histories = $query->paginate(20)->withQueryString();

    //     $entityTypes = BotHistory::distinct()
    //         ->pluck('entity_type')
    //         ->map(fn($type) => [
    //             'label' => class_basename($type),
    //             'value' => $type,
    //         ])
    //         ->values();

    //     return Inertia::render('Admin/BotHistory/Index', [
    //         'histories'   => BotHistoryResource::collection($histories),
    //         'filters'     => $request->only([
    //             'source',
    //             'action',
    //             'entity_type',
    //             'entity_id',
    //             'date_from',
    //             'date_to',
    //         ]),
    //         'entityTypes' => $entityTypes,
    //     ]);
    // }

    public function index(Request $request)
    {
        $query = BotHistory::query()
            ->with('adminUser:id,username')
            ->latest();

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }
        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }
        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }
        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        AdminTableSearch::applyPreset($query, $request->input('search'), 'botHistory');

        $histories = $query->paginate(20)->withQueryString();

        $grouped = $histories->getCollection()->groupBy('entity_type');
        $entityMap = [];

        if ($grouped->has('bot')) {
            $ids = $grouped['bot']->pluck('entity_id')->unique();
            Bot::with('server:id,name')
                ->whereIn('id', $ids)
                ->select(['id', 'name', 'account_name', 'server_id'])
                ->get()
                ->each(function ($bot) use (&$entityMap) {
                    $entityMap["bot:{$bot->id}"] = [
                        'name' => $bot->name,
                        'account_name' => $bot->account_name,
                        'server_id' => $bot->server_id,
                        'server_name' => $bot->server?->name,
                        'deleted' => false,
                    ];
                });
        }

        if ($grouped->has('gem_bot')) {
            $ids = $grouped['gem_bot']->pluck('entity_id')->unique();
            GemBot::with('server:id,name')
                ->whereIn('id', $ids)
                ->select(['id', 'name', 'account_name', 'server_id'])
                ->get()
                ->each(function ($bot) use (&$entityMap) {
                    $entityMap["gem_bot:{$bot->id}"] = [
                        'name' => $bot->name,
                        'account_name' => $bot->account_name,
                        'server_id' => $bot->server_id,
                        'server_name' => $bot->server?->name,
                        'deleted' => false,
                    ];
                });
        }

        // Resolve server name cho các entity đã bị xóa (fallback từ old_data)
        $deletedServerIds = collect();

        $histories->getCollection()->each(function ($history) use ($entityMap, &$deletedServerIds) {
            $key = "{$history->entity_type}:{$history->entity_id}";
            if (! isset($entityMap[$key]) && ! empty($history->old_data['server_id'])) {
                $deletedServerIds->push($history->old_data['server_id']);
            }
        });

        $serverNames = [];
        if ($deletedServerIds->isNotEmpty()) {
            $serverNames = Server::whereIn('id', $deletedServerIds->unique())
                ->pluck('name', 'id')
                ->toArray();
        }

        $histories->getCollection()->transform(function ($history) use (&$entityMap, $serverNames) {
            $key = "{$history->entity_type}:{$history->entity_id}";

            if (isset($entityMap[$key])) {
                $history->entity_info = $entityMap[$key];
            } else {
                // Entity đã bị xóa — fallback từ old_data
                $old = $history->old_data ?? [];
                $serverId = $old['server_id'] ?? null;

                $history->entity_info = ! empty($old) ? [
                    'name' => $old['name'] ?? null,
                    'account_name' => $old['account_name'] ?? null,
                    'server_id' => $serverId,
                    'server_name' => $serverId ? ($serverNames[$serverId] ?? null) : null,
                    'deleted' => true,
                ] : null;
            }

            return $history;
        });

        $entityTypes = BotHistory::distinct()
            ->pluck('entity_type')
            ->map(fn ($type) => [
                'label' => class_basename($type),
                'value' => $type,
            ])
            ->values();

        return Inertia::render('Admin/BotHistory/Index', [
            'histories' => BotHistoryResource::collection($histories),
            'filters' => $request->only([
                'source',
                'action',
                'entity_type',
                'entity_id',
                'date_from',
                'date_to',
                'search',
            ]),
            'entityTypes' => $entityTypes,
        ]);
    }

    public function quick(Request $request, Bot $bot)
    {
        $histories = BotHistory::query()
            ->where('entity_type', 'bot')  // ✅ đúng với DB
            ->where('entity_id', $bot->id)
            ->with('adminUser:id,username')
            ->latest()
            ->limit($request->get('limit', 10))
            ->get();

        return response()->json([
            'data' => BotHistoryResource::collection($histories),
        ]);
    }

    public function quickGem(Request $request, GemBot $gemBot)
    {
        $histories = BotHistory::query()
            ->where('entity_type', 'gem_bot')
            ->where('entity_id', $gemBot->id)
            ->with('adminUser:id,username')
            ->latest()
            ->limit($request->get('limit', 10))
            ->get();

        return response()->json([
            'data' => BotHistoryResource::collection($histories),
        ]);
    }
}
