<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RandomBox\StoreRandomBoxRequest;
use App\Http\Requests\RandomBox\UpdateRandomBoxRequest;
use App\Http\Resources\RandomBox\RandomBoxResource;
use App\Models\Category;
use App\Models\RandomBox;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RandomBoxController extends Controller
{
    public function index(Request $request)
    {
        $randomBoxes = RandomBox::query()
            ->when($request->filled('category_id'), fn($q) => $q->where('category_id', $request->category_id))
            ->when($request->filled('is_public'), fn($q) => $q->where('is_public', $request->is_public))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%');
            })
            ->with(['category'])
            ->orderBy('sort_order')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $categories = Category::where('is_public', true)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Admin/RandomBoxes/Index', [
            'randomBoxes' => RandomBoxResource::collection($randomBoxes),
            'categories' => $categories,
            'filters' => $request->only(['category_id', 'is_public', 'search']),
        ]);
    }

    public function store(StoreRandomBoxRequest $request)
    {

        $data = $request->validated();
        unset($data['image']); // Bỏ image khỏi mass assignment

        $randomBox = RandomBox::create($data);
        if ($request->hasFile('image')) {
            $randomBox->addMediaFromRequest('image')->toMediaCollection('image');
        } elseif ($request->filled('image_url')) {
            $randomBox->addMediaFromUrl($request->image_url)->toMediaCollection('image');
        }

        $imageUrl = $randomBox->getFirstMediaUrl('image'); // ✅ Đây là cách đúng
        $randomBox->image = $imageUrl;
        $randomBox->save(); // ✅ Lưu lại giá trị vào DB


        return redirect()->back()
            ->with('success', 'Tạo hộp random thành công!');
    }

    public function update(UpdateRandomBoxRequest $request, RandomBox $randomBox)
    {
        // Loại bỏ image khỏi validated data
        $data = $request->validated();
        unset($data['image']);

        $randomBox->update($data);
        if ($request->hasFile('image')) {
            $randomBox->clearMediaCollection('image');
            $randomBox->addMediaFromRequest('image')->toMediaCollection('image');
        } elseif ($request->filled('image_url')) {
            $randomBox->clearMediaCollection('image');
            $randomBox->addMediaFromUrl($request->image_url)->toMediaCollection('image');
        }

        $imageUrl = $randomBox->getFirstMediaUrl('image');
        $randomBox->image = $imageUrl;
        $randomBox->save();

        return redirect()->back()
            ->with('success', 'Cập nhật hộp random thành công!');
    }

    public function destroy(RandomBox $randomBox)
    {
        // Soft delete bằng cách set is_public = false
        $randomBox->update(['is_public' => false]);

        return redirect()->back()
            ->with('success', 'Xóa hộp random thành công!');
    }

    public function restore(RandomBox $randomBox)
    {
        $randomBox->update(['is_public' => true]);

        return redirect()->back()
            ->with('success', 'Khôi phục hộp random thành công!');
    }
}
