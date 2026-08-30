<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SpinResult;
use App\Models\Spin;
use App\Http\Resources\Spin\SpinResultResource;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SpinResultController extends Controller
{
    public function index(Request $request)
    {
        $query = SpinResult::query()
            ->with(['user', 'spin']);

        if ($request->filled('spin_id')) {
            $query->where('spin_id', $request->spin_id);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('reward_type')) {
            $query->where('reward_type', $request->reward_type);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        if ($request->filled('search')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        $results = $query->latest()
            ->paginate(50)
            ->withQueryString();

        $spins = Spin::select('id', 'name')->get();

        return Inertia::render('Admin/SpinResults/Index', [
            'results' => [
                'data' => SpinResultResource::collection($results->items())->resolve(),
                'meta' => [
                    'current_page' => $results->currentPage(),
                    'per_page' => $results->perPage(),
                    'total' => $results->total(),
                    'last_page' => $results->lastPage(),
                ],
            ],
            'filters' => $request->only(['spin_id', 'user_id', 'reward_type', 'date_from', 'date_to', 'search']),
            'spins' => $spins,
        ]);
    }

    public function show(SpinResult $result)
    {
        $result->load(['user', 'spin']);

        return Inertia::render('Admin/SpinResults/Show', [
            'result' => (new SpinResultResource($result))->resolve(),
        ]);
    }
}
