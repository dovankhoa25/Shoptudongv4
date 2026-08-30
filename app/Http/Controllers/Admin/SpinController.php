<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Spin\SpinResource;
use App\Models\Spin;
use App\Models\Category;

use Illuminate\Http\Request;
use Inertia\Inertia;

class SpinController extends Controller
{
    public function index(Request $request)
    {
        $query = Spin::query()
            ->with('category')
            ->withCount(['rewards', 'results', 'tickets']);

        // Search
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // Filter by type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Filter by category
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Filter by status
        if ($request->filled('is_public')) {
            $query->where('is_public', $request->is_public);
        }

        $spins = $query->orderBy('sort_order')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Spins/Index', [
            'spins' => SpinResource::collection($spins),
            'filters' => $request->only(['search', 'type', 'category_id', 'is_public']),
            'categories' => Category::select('id', 'name')->get(),
        ]);
    }

    public function create()
    {
        $categories = Category::select('id', 'name')->get();

        return Inertia::render('Admin/Spins/Create', [
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'image_file' => 'nullable|image|max:2048',
            'image_url' => 'nullable|url',
            'type' => 'required|in:wheel,flip',
            'price_per_turn' => 'required|numeric|min:0',
            'total_slots' => 'required|integer|min:2|max:24',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
            'description' => 'nullable|string',
        ]);

        $validated['is_public'] = $request->has('is_public')
            ? filter_var($request->is_public, FILTER_VALIDATE_BOOLEAN)
            : true;

        unset($validated['image_file'], $validated['image_url']);

        $spin = Spin::create($validated);

        if ($request->hasFile('image_file')) {
            $spin->addMediaFromRequest('image_file')->toMediaCollection('image');
        } elseif ($request->filled('image_url')) {
            $spin->addMediaFromUrl($request->image_url)->toMediaCollection('image');
        }

        if ($spin->hasMedia('image')) {
            $spin->update(['image' => $spin->getFirstMediaUrl('image')]);
        }

        return redirect()->route('admin.spins.index')
            ->with('success', 'Vòng quay đã được tạo thành công!');
    }

    public function show(Spin $spin)
    {
        $spin->load([
            'category',
            'rewards',
            'results' => function ($query) {
                $query->with('user')->latest()->limit(50);
            }
        ]);

        return response()->json(new SpinResource($spin));
    }

    public function edit(Spin $spin)
    {
        $categories = Category::select('id', 'name')->get();

        return Inertia::render('Admin/Spins/Edit', [
            'spin' => new SpinResource($spin->load('category')),
            'categories' => $categories,
        ]);
    }

    public function update(Request $request, Spin $spin)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'name' => 'required|string|max:255',
            'image_file' => 'nullable|image|max:2048',
            'image_url' => 'nullable|url',
            'type' => 'required|in:wheel,flip',
            'price_per_turn' => 'required|numeric|min:0',
            'total_slots' => 'required|integer|min:2|max:24',
            'is_public' => 'boolean',
            'sort_order' => 'integer',
            'description' => 'nullable|string',
        ]);

        $validated['is_public'] = filter_var($request->is_public, FILTER_VALIDATE_BOOLEAN);

        unset($validated['image_file'], $validated['image_url']);

        $spin->update($validated);

        if ($request->hasFile('image_file')) {
            $spin->clearMediaCollection('image');
            $spin->addMediaFromRequest('image_file')->toMediaCollection('image');
        } elseif ($request->filled('image_url') && $request->image_url !== $spin->getFirstMediaUrl('image')) {
            $spin->clearMediaCollection('image');
            $spin->addMediaFromUrl($request->image_url)->toMediaCollection('image');
        }

        if ($spin->hasMedia('image')) {
            $spin->update(['image' => $spin->getFirstMediaUrl('image')]);
        }

        return redirect()->route('admin.spins.index')
            ->with('success', 'Vòng quay đã được cập nhật!');
    }

    public function destroy(Spin $spin)
    {
        $spin->clearMediaCollection('image');
        $spin->delete();

        return redirect()->route('admin.spins.index')
            ->with('success', 'Vòng quay đã được xóa!');
    }

    public function updateOrder(Request $request)
    {
        $request->validate([
            'orders' => 'required|array',
            'orders.*' => 'required|integer|exists:spins,id',
        ]);

        foreach ($request->orders as $index => $id) {
            Spin::where('id', $id)->update(['sort_order' => $index]);
        }

        return response()->json(['success' => true, 'message' => 'Đã cập nhật thứ tự!']);
    }
}
