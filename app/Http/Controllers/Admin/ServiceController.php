<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Services\FilterServiceRequest;
use App\Http\Requests\Services\ServicesStoreRequest;
use App\Http\Requests\Services\ServicesUpdateRequest;
use App\Http\Resources\Service\ServiceResource;
use App\Models\Service;
use App\Support\AdminTableSearch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(FilterServiceRequest $request): Response
    {
        $perPage = $request->input('per_page', 15);
        $search = $request->input('search');
        $status = $request->input('status');

        $query = Service::with('categories');
        AdminTableSearch::applyPreset($query, $search, 'services');

        if (! is_null($status)) {
            $query->where('status', $status);
        }

        $services = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Service/Index', [
            'services' => ServiceResource::collection($services),
            'filters' => [
                'search' => $search,
                'status' => $status,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function store(ServicesStoreRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $data['status'] = true; // Luôn true khi thêm mới
        $data['name'] = $data['name'];
        $data['default_price'] = $data['default_price'] ?? 0;
        $data['original_price'] = $data['original_price'] ?? 0;
        $data['description'] = $data['description'] ?? null;
        $data['is_popular'] = $data['is_popular'] ?? false;
        $data['processing_time'] = $data['processing_time'] ?? null;
        $data['warranty'] = $data['warranty'] ?? null;

        Service::create($data);

        return Redirect::route('admin.services.index')->with('success', 'Service created.');
    }

    public function update(int $sv, ServicesUpdateRequest $request): RedirectResponse
    {
        $svFind = Service::find($sv);

        if (! $svFind) {
            return Redirect::back()->with('error', 'Service không tồn tại.');
        }

        $svFind->name = $request->name ?? $svFind->name;
        $svFind->default_price = $request->default_price ?? $svFind->default_price;
        $svFind->original_price = $request->original_price ?? $svFind->original_price;
        $svFind->description = $request->description ?? $svFind->description;
        $svFind->is_popular = $request->is_popular ?? $svFind->is_popular;
        $svFind->processing_time = $request->processing_time ?? $svFind->processing_time;
        $svFind->warranty = $request->warranty ?? $svFind->warranty;
        $svFind->status = $request->status ?? $svFind->status;

        $svFind->save();

        return Redirect::back()->with('success', 'Service updated.');
    }

    public function updateCategories(Request $request, $id)
    {
        $request->validate([
            'categories' => 'array',
            'categories.*' => 'integer|exists:categories,id',
        ]);

        $service = Service::findOrFail($id);
        $service->categories()->sync($request->categories);

        return back()->with('success', 'Gán danh mục thành công.');
    }
}
