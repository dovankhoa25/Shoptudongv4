<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attribute\StoreAttributeOptionRequest;
use App\Http\Requests\Attribute\StoreAttributeRequest;
use App\Http\Requests\Attribute\UpdateAttributeOptionRequest;
use App\Http\Requests\Attribute\UpdateAttributeRequest;
use App\Http\Resources\Attribute\AttributeResource;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Support\AdminTableSearch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AttributeController extends Controller
{
    public function index(Request $request)
    {
        $attributes = Attribute::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn ($query) => AdminTableSearch::applyPreset($query, $request->input('search'), 'attributes'))
            ->with('options')
            ->orderBy('name')
            ->paginate(20);

        return Inertia::render('Admin/Attributes/Index', [
            'attributes' => AttributeResource::collection($attributes),
            'filters' => $request->only('search', 'status'),
        ]);
    }

    public function store(StoreAttributeRequest $request)
    {
        $attribute = Attribute::create($request->validated());

        return redirect()->back()
            ->with('success', 'Tạo thành công!');
    }

    public function update(UpdateAttributeRequest $request, Attribute $attribute)
    {
        $attribute->update($request->validated());

        return redirect()->back()
            ->with('success', 'Cập nhật thành công!');
    }

    public function destroy(Attribute $attribute)
    {
        $attribute->delete();

        return redirect()->back()
            ->with('success', 'Xóa thành công!');
    }

    public function attribute()
    {
        $attribute = Attribute::orderBy('id')->get();

        return response()->json(AttributeResource::collection($attribute));
    }

    // OPTIONS CRUD

    public function storeOption(StoreAttributeOptionRequest $request)
    {
        $option = AttributeOption::create($request->validated());

        return redirect()->back()
            ->with('success', 'Tạo thành công!');
    }

    public function updateOption(UpdateAttributeOptionRequest $request, AttributeOption $option)
    {
        $option->update($request->validated());

        return redirect()->back()
            ->with('success', 'Cập nhật thành công!');
    }

    public function destroyOption(AttributeOption $option)
    {
        $option->delete();

        return redirect()->back()
            ->with('success', 'Xóa thành công!');
    }
}
