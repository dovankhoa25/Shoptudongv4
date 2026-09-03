<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\CarotRechargeResource;
use App\Http\Resources\Api\CarotRechargeStatisticResource;
use App\Models\CarotRecharge;
use App\Models\CarotRechargeStatistic;
use App\Support\AdminTableSearch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CarotRechargeController extends Controller
{
    public function index(Request $request)
    {
        $query = CarotRecharge::with('user:id,username,email');

        $this->applyFilters($query, $request);

        $sortBy = in_array($request->get('sort_by'), ['created_at', 'amount', 'carot', 'status'], true)
            ? $request->get('sort_by')
            : 'created_at';
        $sortOrder = $request->get('sort_order') === 'asc' ? 'asc' : 'desc';

        $recharges = $query->orderBy($sortBy, $sortOrder)
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/CarotRecharges/Index', [
            'recharges' => CarotRechargeResource::collection($recharges),
            'filters' => $request->only(['search', 'status', 'server_id', 'date_from', 'date_to']),
            'stats' => $this->getFilteredStats($request),
            'statistics' => [
                'type' => $this->resolveStatType($request),
                'items' => CarotRechargeStatisticResource::collection(
                    $this->getStatisticsQuery($request)->get()
                ),
            ],
        ]);
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('server_id')) {
            $query->where('server_id', (int) $request->server_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        AdminTableSearch::applyPreset($query, $request->input('search'), 'carotRecharges');

        return $query;
    }

    private function getFilteredStats(Request $request): array
    {
        $baseQuery = CarotRecharge::query();
        $this->applyFilters($baseQuery, $request);

        return [
            'total_recharges' => (clone $baseQuery)->count(),
            'pending_count' => (clone $baseQuery)->where('status', CarotRecharge::STATUS_PENDING)->count(),
            'success_count' => (clone $baseQuery)->where('status', CarotRecharge::STATUS_SUCCESS)->count(),
            'failed_count' => (clone $baseQuery)->where('status', CarotRecharge::STATUS_FAILED)->count(),
            'cancelled_count' => (clone $baseQuery)->where('status', CarotRecharge::STATUS_CANCELLED)->count(),
            'total_amount' => (int) (clone $baseQuery)->sum('amount'),
            'total_carot' => (int) (clone $baseQuery)->sum('carot'),
            'success_amount' => (int) (clone $baseQuery)->where('status', CarotRecharge::STATUS_SUCCESS)->sum('amount'),
            'success_carot' => (int) (clone $baseQuery)->where('status', CarotRecharge::STATUS_SUCCESS)->sum('carot'),
            'today_count' => (clone $baseQuery)->whereDate('created_at', today())->count(),
            'today_amount' => (int) (clone $baseQuery)->whereDate('created_at', today())->sum('amount'),
        ];
    }

    private function resolveStatType(Request $request): string
    {
        $type = $request->get('stat_type', CarotRechargeStatistic::TYPE_DAILY);

        if (! in_array($type, [
            CarotRechargeStatistic::TYPE_DAILY,
            CarotRechargeStatistic::TYPE_MONTHLY,
            CarotRechargeStatistic::TYPE_YEARLY,
        ], true)) {
            $type = CarotRechargeStatistic::TYPE_DAILY;
        }

        return $type;
    }

    private function getStatisticsQuery(Request $request)
    {
        $limit = match ($this->resolveStatType($request)) {
            CarotRechargeStatistic::TYPE_MONTHLY => 24,
            CarotRechargeStatistic::TYPE_YEARLY => 10,
            default => 30,
        };

        return CarotRechargeStatistic::whereNull('user_id')
            ->whereNull('server_id')
            ->where('type', $this->resolveStatType($request))
            ->latest('stat_date')
            ->limit($limit);
    }
}
