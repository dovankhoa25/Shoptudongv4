<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApiGameTypeResource;
use App\Models\Category;
use App\Models\GameType;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {

  
        $gameTypes = GameType::with(['categories' => function ($query) {
            $query->orderBy('sort_order');
        }])
            ->orderBy('sort_order')
            ->get();

        return ApiGameTypeResource::collection($gameTypes);
    }

    // public function servicesBySlug($slug)
    // {
    //     $category = Category::where('slug', $slug)
    //         ->with([
    //             'categoryTemplate',
    //             'services' => function ($q) {
    //                 $q->select([
    //                     'services.id',
    //                     'name',
    //                     'default_price',
    //                     'original_price',
    //                     'description',
    //                     'status',
    //                     'is_popular',
    //                     'processing_time',
    //                     'warranty',

    //                 ])
    //                     ->with(['fields' => function ($q) {
    //                         $q->select(['fields.id', 'label', 'field_key', 'type', 'options']);
    //                     }]);
    //             }
    //         ])
    //         ->firstOrFail();

    //     // Ẩn pivot giữa category ↔ services
    //     $category->services->each->makeHidden('pivot');

    //     // Ẩn pivot giữa service ↔ fields (nếu có)
    //     foreach ($category->services as $service) {
    //         $service->fields->each->makeHidden('pivot');
    //     }

    //     return response()->json([
    //         'category' => $category->only(['id', 'name', 'slug']),
    //         'categoryTemplate' => $category->categoryTemplate,
    //         'services' => $category->services,
    //     ]);
    // }

    public function servicesBySlug($slug)
    {
        $category = Category::where('slug', $slug)
            ->with([
                'categoryTemplate',
                'services' => function ($q) {
                    $q->where('status', true)
                        ->select([
                            'services.id',
                            'name',
                            'default_price',
                            'original_price',
                            'description',
                            'status',
                            'is_popular',
                            'processing_time',
                            'warranty',
                        ])
                        ->with(['fields' => function ($q) {
                            $q->select(['fields.id', 'label', 'field_key', 'type', 'options']);
                        }]);
                }
            ])
            ->firstOrFail();

        // Ẩn pivot giữa category ↔ services
        $category->services->each->makeHidden('pivot');

        // Ẩn pivot giữa service ↔ fields (nếu có)
        foreach ($category->services as $service) {
            $service->fields->each->makeHidden('pivot');
        }

        return response()->json([
            'category' => $category->only(['id', 'name', 'slug']),
            'categoryTemplate' => $category->categoryTemplate,
            'services' => $category->services,
        ]);
    }
}
