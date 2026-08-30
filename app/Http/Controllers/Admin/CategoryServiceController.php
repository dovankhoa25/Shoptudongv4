<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Service;
use Illuminate\Http\Request;

class CategoryServiceController extends Controller
{
    public function index(Request $request)
    {
        $categories = Category::select(['id', 'name', 'status'])
            ->where('status', true)
            ->where('template', 'service')
            ->with(['services' => function ($q) {
                $q->select(['services.id', 'name', 'status']);
            }])
            ->get();

        $selectedCategory = null;

        if ($request->category) {
            $selectedCategory = Category::select(['id', 'name'])
                ->with(['services' => function ($q) {
                    $q->select(['services.id', 'name', 'status']);
                }])
                ->find($request->category);

            if ($selectedCategory) {
                $selectedCategory->services->each->makeHidden('pivot');
            }
        }

        $allServices = Service::select(['id', 'name', 'status'])->where('status', true)->get();

        return inertia('Admin/CategoryServices/Index', [
            'categories' => $categories,
            'selectedCategory' => $selectedCategory,
            'allServices' => $allServices,
        ]);
    }

    /**
     * Gán Services cho Category.
     */
    public function assign(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'service_ids' => 'required|array',
            'service_ids.*' => 'exists:services,id',
        ]);

        $category = Category::find($validated['category_id']);
        $category->services()->syncWithoutDetaching($validated['service_ids']);

        return redirect()->back()
            ->with('success', 'Gán service cho category thành công!');
    }

    /**
     * Bỏ gán Service khỏi Category.
     */
    public function remove(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
            'service_id' => 'required|exists:services,id',
        ]);

        $category = Category::find($validated['category_id']);
        $category->services()->detach($validated['service_id']);

        return redirect()->back()
            ->with('success', 'Bỏ service khỏi category thành công!');
    }
}
