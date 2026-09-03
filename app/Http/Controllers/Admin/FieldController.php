<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Field\FieldResource;
use App\Models\Field;
use App\Models\Service;
use App\Support\AdminTableSearch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FieldController extends Controller
{
    /**
     * Hiển thị danh sách Fields kèm Services đã gán
     */
    public function index(Request $request)
    {
        $query = Field::query()->with('services');
        AdminTableSearch::applyPreset($query, $request->input('search'), 'fields');

        $fields = $query->paginate($request->input('per_page', 15))->withQueryString();

        return Inertia::render('Admin/Field/Index', [
            'fields' => FieldResource::collection($fields),
            'filters' => $request->only('search'),
        ]);
    }

    /**
     * Tạo mới Field và gán Services
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'field_key' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:text,textarea,number,select'],
            'options' => ['nullable', 'array'],
            'required' => ['required', 'boolean'],
        ]);

        $field = Field::create([
            'label' => $data['label'],
            'field_key' => $data['field_key'],
            'type' => $data['type'],
            'options' => $data['options'],
            'required' => $data['required'],
        ]);

        // Gán quan hệ services
        // $field->services()->sync($data['service_ids']);

        return redirect()->back()->with('success', 'Tạo Field thành công!');
    }

    /**
     * Cập nhật Field & Services
     */
    public function update(Request $request, Field $field)
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'field_key' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:text,textarea,number,select'],
            'options' => ['nullable', 'array'],
            'required' => ['required', 'boolean'],
        ]);

        $field->update([
            'label' => $data['label'],
            'field_key' => $data['field_key'],
            'type' => $data['type'],
            'options' => $data['options'],
            'required' => $data['required'],
        ]);

        return redirect()->back()->with('success', 'Cập nhật Field thành công!');
    }

    public function fields()
    {
        $field = Field::orderBy('id')->get();

        return response()->json($field);
    }
}
