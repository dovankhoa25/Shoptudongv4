<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\GameType\StoreGameTypeRequest;
use App\Http\Requests\GameType\UpdateGameTypeRequest;
use App\Http\Resources\GameType\GameTypeResource;
use App\Models\GameType;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GameTypeController extends Controller
{
    public function index(Request $request)
    {
        $GameTypes = GameType::query()
            ->when($request->filled('name'), fn ($q) => $q->where('name', 'like', "%{$request->name}%"))
            ->withCount('categories')
            ->orderBy('sort_order')
            ->paginate(20);

        return Inertia::render('Admin/GameTypes/Index', [
            'gameTypes' => GameTypeResource::collection($GameTypes),
            'filters' => $request->only('name'),
        ]);
    }

    public function store(StoreGameTypeRequest $request)
    {
        $gameType = GameType::create($request->validated());

        return redirect()->route('admin.games.gametypes.index')
            ->with('success', 'Tạo loại game thành công!');
    }

    public function update(UpdateGameTypeRequest $request, GameType $gametype)
    {
        $gametype->update($request->validated());

        return redirect()->route('admin.games.gametypes.index')
            ->with('success', 'Cập nhật loại game thành công!');
    }

    public function destroy(GameType $gametype)
    {
        $gametype->delete();

        return redirect()->route('admin.games.gametypes.index')
            ->with('success', 'Xóa loại game thành công!');
    }
}
