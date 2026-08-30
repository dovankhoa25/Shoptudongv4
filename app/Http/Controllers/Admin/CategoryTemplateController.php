<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CategoryTemplate;
use Illuminate\Http\Request;

class CategoryTemplateController extends Controller
{
    /**
     * Danh sách Category kèm Template.
     */
    public function index(Request $request)
    {
        $categories = Category::select(['id', 'name', 'status'])
            ->where('template', 'service')
            ->where('status', true)
            ->with('categoryTemplate')
            ->get();

        $selectedCategory = null;

        if ($request->category_id) {
            $selectedCategory = Category::with('categoryTemplate')->find($request->category_id);
        }

        return inertia('Admin/CategoryTemplates/Index', [
            'categories' => $categories,
            'selectedCategory' => $selectedCategory,
        ]);
    }

    /**
     * Tạo mới hoặc cập nhật Template.
     */
    public function storeOrUpdate(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'features' => 'nullable|array',
            'requirements' => 'nullable|array',
            'instructions' => 'nullable|array',
            'faq' => 'nullable|array',
        ]);

        $template = CategoryTemplate::updateOrCreate(
            ['category_id' => $validated['category_id']],
            [
                'features' => $validated['features'],
                'requirements' => $validated['requirements'],
                'instructions' => $validated['instructions'],
                'faq' => $validated['faq'],
            ]
        );

        return redirect()->back()
            ->with('success', 'Lưu Template thành công!');
    }

    /**
     * Xoá Template.
     */
    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
        ]);

        CategoryTemplate::where('category_id', $validated['category_id'])->delete();

        return redirect()->back()
            ->with('success', 'Xoá Template thành công!');
    }
}
