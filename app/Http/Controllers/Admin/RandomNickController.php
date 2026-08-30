<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RandomNick\BulkStoreRandomNickRequest;
use App\Http\Requests\RandomNick\StoreRandomNickRequest;
use App\Http\Requests\RandomNick\UpdateRandomNickRequest;
use App\Http\Resources\RandomNick\RandomNickResource;
use App\Models\RandomBox;
use App\Models\RandomNick;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RandomNickController extends Controller
{

    public function index(Request $request)
    {
        $randomNicks = RandomNick::query()
            ->with(['randomBox.category'])
            ->when($request->filled('random_box_id'), fn($q) => $q->where('random_box_id', $request->random_box_id))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where(function ($query) use ($request) {
                    $query->where('account', 'like', '%' . $request->search . '%')
                        ->orWhere('description', 'like', '%' . $request->search . '%');
                });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        // Load random boxes for filter dropdown
        $randomBoxes = RandomBox::with('category')
            ->where('is_public', true)
            ->orderBy('name')
            ->get(['id', 'name', 'category_id', 'price']);

        return Inertia::render('Admin/RandomNicks/Index', [
            'randomNicks' => RandomNickResource::collection($randomNicks),
            'randomBoxes' => $randomBoxes,
            'filters' => $request->only(['random_box_id', 'status', 'search']),
            'stats' => $this->getStats($request->random_box_id),
        ]);
    }

    private function getStats($randomBoxId = null)
    {
        $query = RandomNick::query();

        if ($randomBoxId) {
            $query->where('random_box_id', $randomBoxId);
        }

        return [
            'total' => $query->count(),
            'available' => $query->where('status', 'available')->count(),
            'taken' => $query->where('status', 'taken')->count(),
        ];
    }

    public function store(StoreRandomNickRequest $request)
    {
        $data = $request->validated();
        unset($data['image']);
        $data['user_id'] = auth()->id();
        $randomNick = RandomNick::create($data);

        if ($request->hasFile('image')) {
            $randomNick->addMediaFromRequest('image')->toMediaCollection('image');
        }

        return redirect()->back()
            ->with('success', 'Tạo nick random thành công!');
    }

    public function bulkStore(BulkStoreRandomNickRequest $request)
    {
        $data = $request->validated();
        $randomBoxId = $data['random_box_id'];
        $nickData = $data['nick_data']; // "tk|mk|mô tả\ntk2|mk2|mô tả2"
        $sharedImage = $request->file('shared_image');
        $userId = auth()->id();
        DB::beginTransaction();

        try {
            $lines = array_filter(array_map('trim', explode("\n", $nickData)));
            $createdNicks = [];

            foreach ($lines as $line) {
                $parts = array_map('trim', explode('|', $line));

                if (count($parts) < 2) {
                    continue; // Skip invalid lines
                }

                $account = $parts[0];
                $password = $parts[1];
                $description = $parts[2] ?? null;

                // Check for duplicate account in same random box
                // $exists = RandomNick::where('random_box_id', $randomBoxId)
                //     ->where('account', $account)
                //     ->exists();

                // if ($exists) {
                //     continue; // Skip duplicates
                // }

                $nick = RandomNick::create([
                    'random_box_id' => $randomBoxId,
                    'account' => $account,
                    'password' => $password,
                    'description' => $description,
                    'status' => 'available',
                    'user_id' => auth()->id(),

                ]);

                // Add shared image if provided
                if ($sharedImage) {
                    $nick->addMedia($sharedImage->getPathname())
                        ->usingName($sharedImage->getClientOriginalName())
                        ->usingFileName(time() . '_' . $nick->id . '.' . $sharedImage->getClientOriginalExtension())
                        ->toMediaCollection('image');
                }

                $createdNicks[] = $nick;
            }

            DB::commit();

            $count = count($createdNicks);
            return redirect()->back()
                ->with('success', "Đã tạo thành công {$count} nick random!");
        } catch (\Exception $e) {
            DB::rollback();

            return redirect()->back()
                ->with('error', 'Có lỗi xảy ra khi tạo nick: ' . $e->getMessage());
        }
    }

    public function update(UpdateRandomNickRequest $request, RandomNick $randomNick)
    {
        $data = $request->validated();
        unset($data['image']);

        $randomNick->update($data);

        if ($request->hasFile('image')) {
            $randomNick->clearMediaCollection('image');
            $randomNick->addMediaFromRequest('image')->toMediaCollection('image');
        }

        return redirect()->back()
            ->with('success', 'Cập nhật nick random thành công!');
    }

    public function destroy(RandomNick $randomNick)
    {
        // Soft delete - mark as deleted
        $randomNick->markAsDeleted();

        return redirect()->back()
            ->with('success', 'Xóa nick random thành công!');
    }

    public function restore(RandomNick $randomNick)
    {
        $randomNick->markAsAvailable();

        return redirect()->back()
            ->with('success', 'Khôi phục nick random thành công!');
    }

    public function changeStatus(Request $request, RandomNick $randomNick)
    {
        $request->validate([
            'status' => 'required|in:available,taken,deleted'
        ]);

        $randomNick->update(['status' => $request->status]);

        return redirect()->back()
            ->with('success', 'Cập nhật trạng thái thành công!');
    }

    // Random pick for purchase (used by frontend/API)
    public function getRandomAvailable(RandomBox $randomBox)
    {
        $randomNick = $randomBox->availableNicks()->inRandomOrder()->first();

        if (!$randomNick) {
            return response()->json([
                'success' => false,
                'message' => 'Hộp random này đã hết nick!'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new RandomNickResource($randomNick)
        ]);
    }
}
