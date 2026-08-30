<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserCategoryController extends Controller
{


    public function index(User $user)
    {
        // Lấy tất cả categories và nhóm theo template
        $allCategories = Category::select('id', 'name', 'slug', 'template')
            ->where('status', 'active')
            ->orderBy('template')
            ->orderBy('name')
            ->get()
            ->groupBy('template');

        // Lấy categories đã được assign cho user (với pivot data)
        $userCategories = $user->categories()
            ->select('categories.id', 'categories.name', 'categories.slug', 'categories.template')
            ->withPivot('can_post')
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'template' => $category->template,
                    'can_post' => (bool) $category->pivot->can_post,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                ],
                'all_categories' => $allCategories,
                'user_categories' => $userCategories,
            ],
        ]);
    }

    public function syncCategories(Request $request, User $user)
    {
        $validated = $request->validate([
            'categories' => 'required|array|min:1',
            'categories.*.id' => 'required|exists:categories,id',
            'categories.*.can_post' => 'boolean',
        ]);

        DB::transaction(function () use ($user, $validated) {
            $syncData = [];

            foreach ($validated['categories'] as $cat) {
                $syncData[$cat['id']] = [
                    'can_post' => $cat['can_post'] ?? true,
                ];
            }

            // ⚡ Sync categories (replace tất cả)
            $user->categories()->sync($syncData);
        });

        // Lấy lại danh sách categories sau khi sync
        $updatedCategories = $user->categories()
            ->select('categories.id', 'categories.name', 'categories.slug')
            ->withPivot('can_post')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Cập nhật danh mục cho cộng tác viên thành công!',
            'data' => $updatedCategories,
        ]);
    }
}
