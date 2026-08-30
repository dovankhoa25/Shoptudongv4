<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CategoryAttributeController extends Controller
{
    public function index(Request $request)
    {
        $categories = Category::select(['id', 'name', 'status'])
            ->with([
                'attributes' => function ($q) {
                    $q->select(['attributes.id', 'name', 'status']);
                },
                'attributes.options' => function ($q) {
                    $q->select(['id', 'attribute_id', 'option_value', 'status']);
                }
            ])
            ->get();

        $selectedCategory = null;
        if ($request->category) {
            $selectedCategory = Category::select(['id', 'name'])
                ->with([
                    'attributes' => function ($q) {
                        $q->select(['attributes.id', 'name']);
                    },
                    'attributes.options' => function ($q) {
                        $q->select(['id', 'attribute_id', 'option_value', 'status']);
                    }
                ])
                ->find($request->category);

            if ($selectedCategory) {
                $selectedCategory->attributes->each->makeHidden('pivot');
            }
        }

        return Inertia::render('Admin/CategoryAttributes/Index', [
            'categories' => $categories,
            'selectedCategory' => $selectedCategory
        ]);
    }

    public function assign(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'attribute_ids' => 'required|array',
            'attribute_ids.*' => 'exists:attributes,id'
        ]);

        $category = Category::find($validated['category_id']);
        $category->attributes()->syncWithoutDetaching($validated['attribute_ids']);

        return redirect()->back()
            ->with('success', 'Cập nhật danh mục thành công!');
    }

    public function remove(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'attribute_id' => 'required|exists:attributes,id'
        ]);

        $category = Category::find($validated['category_id']);
        $category->attributes()->detach($validated['attribute_id']);

        return redirect()->back()
            ->with('success', 'Cập nhật danh mục thành công!');
    }
}
