<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission as AppPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\Category\CategoryResource;
use App\Http\Resources\GameType\GameTypeResource;
use App\Models\Category;
use App\Models\GameType;
use App\Support\AdminTableSearch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = Category::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn ($query) => AdminTableSearch::applyPreset($query, $request->input('search'), 'categories'))
            ->orderBy('sort_order')
            ->paginate(20);

        return Inertia::render('Admin/Categories/Index', [
            'categories' => CategoryResource::collection($categories),
            'filters' => $request->only('search', 'status'),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Categories/Create', [
            'gameTypes' => GameTypeResource::collection(GameType::orderBy('sort_order')->get()),
        ]);
    }

    public function store(StoreCategoryRequest $request)
    {
        $data = $request->validated();

        $category = Category::create($data);

        if ($request->hasFile('image')) {
            $category->addMediaFromRequest('image')->toMediaCollection('image');
        } elseif ($request->filled('image_url')) {
            $category->addMediaFromUrl($request->image_url)->toMediaCollection('image');
        }

        return redirect()->route('admin.games.categories.index')
            ->with('success', 'Tạo danh mục thành công!');
    }

    public function edit(Category $category)
    {
        return Inertia::render('Admin/Categories/Edit', [
            'category' => new CategoryResource($category),
            'gameTypes' => GameTypeResource::collection(GameType::orderBy('sort_order')->get()),
        ]);
    }

    public function update(UpdateCategoryRequest $request, Category $category)
    {
        $data = $request->validated();

        // Convert is_public từ true/false (string) về boolean
        $data['is_public'] = filter_var($data['is_public'], FILTER_VALIDATE_BOOLEAN);

        $category->update($data);

        // Nếu có image_file (upload) hoặc image_url
        if ($request->hasFile('image_file')) {
            $category->clearMediaCollection('image');
            $category->addMediaFromRequest('image_file')->toMediaCollection('image');
        } elseif ($request->filled('image_url') && $request->image_url !== $category->getFirstMediaUrl('image')) {
            $category->clearMediaCollection('image');
            $category->addMediaFromUrl($request->image_url)->toMediaCollection('image');
        }

        return redirect()->route('admin.games.categories.index')
            ->with('success', 'Cập nhật danh mục thành công!');
    }

    public function destroy(Category $category)
    {
        $category->delete();

        return redirect()->route('admin.games.categories.index')
            ->with('success', 'Xóa danh mục thành công!');
    }

    public function gameTypes()
    {
        $gameTypes = GameType::orderBy('sort_order')->get();

        return response()->json(GameTypeResource::collection($gameTypes));
    }

    public function getAttributes(Request $request, Category $category)
    {
        $user = $request->user();
        $canViewAllAttributes = $user->canViewAllAdminData()
            || $user->hasAnyPermission([
                AppPermission::AttributesView->value,
                AppPermission::AttributesManage->value,
            ]);

        if (! $canViewAllAttributes) {
            $canPostToCategory = $user->categories()
                ->whereKey($category->id)
                ->wherePivot('can_post', true)
                ->exists();

            abort_unless($canPostToCategory, 403, 'Bạn không được đăng nick vào danh mục này.');
        }

        // Lấy các attributes đã được gán cho category này, kèm theo options
        $attributes = $category->attributes()
            ->with(['options' => function ($query) {
                $query->orderBy('option_value');
            }])
            ->where('status', true) // Chỉ lấy attributes đang hoạt động
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $attributes->map(function ($attribute) {
                return [
                    'id' => $attribute->id,
                    'name' => $attribute->name,
                    'status' => $attribute->status,
                    'options' => $attribute->options->map(function ($option) {
                        return [
                            'id' => $option->id,
                            'option_value' => $option->option_value,
                            'status' => $option->status,
                        ];
                    }),
                ];
            }),
        ]);
    }
}
