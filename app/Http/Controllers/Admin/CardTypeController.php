<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CardType\CardTypeResource;
use App\Models\CardType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class CardTypeController extends Controller
{
    public function index(Request $request)
    {
        $cardTypes = CardType::query()
            ->when(
                $request->filled('status'),
                fn($q) => $q->where('status', $request->status)
            )
            ->orderBy('telco')
            ->paginate(20);

        return Inertia::render('Admin/CardTypes/Index', [
            'card_types' => CardTypeResource::collection($cardTypes),
            'filters'    => $request->only('status'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'telco'          => 'required|string|unique:card_types,telco',
            'discount_rate'  => 'nullable|numeric|min:0|max:100',
            'status'         => 'boolean',
        ]);


        CardType::create([
            'telco'         => $validated['telco'],
            'discount_rate' => $validated['discount_rate'] ?? 0,
            'status'        => $validated['status'] ?? true,
        ]);

        return redirect()->back()->with('success', 'Tạo thành công!');
    }

    public function update(Request $request, CardType $cardtype)
    {
        try {
            $validated = $request->validate([
                'telco'         => 'required|string|unique:card_types,telco,' . $cardtype->id,
                'discount_rate' => 'nullable|numeric|min:0|max:100',
                'status'        => 'boolean',
            ]);

            // Debug xem dữ liệu đầu vào:
            Log::info('VALIDATED:', $validated);
            Log::info('BEFORE UPDATE:', $cardtype->toArray());

            $updated = $cardtype->update([
                'telco'         => $validated['telco'],
                'discount_rate' => $validated['discount_rate'] ?? 0,
                'status'        => $validated['status'] ?? true,
            ]);

            Log::info('UPDATED:', [$updated]);
            Log::info('AFTER UPDATE:', $cardtype->fresh()->toArray());

            return redirect()->back()->with('success', 'Cập nhật thành công!');
        } catch (\Exception $e) {
            Log::error('UPDATE ERROR: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Lỗi: ' . $e->getMessage());
        }
    }


    public function destroy(CardType $cardtype)
    {
        $cardtype->delete();

        return redirect()->back()->with('success', 'Xóa thành công!');
    }
}
