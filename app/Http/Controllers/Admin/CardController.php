<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Card\CardResource;
use App\Models\Card;
use App\Support\AdminTableSearch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CardController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:pending,confirmed,completed,failed'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'code' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = $validated['per_page'] ?? 20;

        $query = Card::query()->with(['user', 'cardType']);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (! empty($validated['code'])) {
            $query->where('code', 'like', "%{$validated['code']}%");
        }

        AdminTableSearch::applyPreset($query, $validated['search'] ?? null, 'cards');

        $cards = $query->latest()->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Cards/Index', [
            'cards' => CardResource::collection($cards),
            'filters' => $request->only(['search', 'status', 'user_id', 'code']),
        ]);
    }
}
