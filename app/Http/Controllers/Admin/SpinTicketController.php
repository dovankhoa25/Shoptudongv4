<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SpinTicket;
use App\Models\User;
use App\Models\Spin;
use App\Http\Resources\Spin\SpinTicketResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SpinTicketController extends Controller
{
    public function index(Request $request)
    {
        $query = SpinTicket::query()
            ->with(['user', 'spin']);

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('spin_id')) {
            $query->where('spin_id', $request->spin_id);
        }

        if ($request->filled('search')) {
            $query->whereHas('user', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                    ->orWhere('email', 'like', '%' . $request->search . '%');
            });
        }

        $tickets = $query->latest()
            ->paginate(50)
            ->withQueryString();

        $spins = Spin::select('id', 'name')->get();

        return Inertia::render('Admin/SpinTickets/Index', [
            'tickets' => [
                'data' => SpinTicketResource::collection($tickets->items())->resolve(),
                'meta' => [
                    'current_page' => $tickets->currentPage(),
                    'per_page' => $tickets->perPage(),
                    'total' => $tickets->total(),
                    'last_page' => $tickets->lastPage(),
                ],
            ],
            'filters' => $request->only(['user_id', 'spin_id', 'search']),
            'spins' => $spins,
        ]);
    }

    public function create()
    {
        $spins = Spin::select('id', 'name', 'type', 'image')->get();

        return Inertia::render('Admin/SpinTickets/Create', [
            'spins' => $spins,
        ]);
    }



    public function edit(SpinTicket $ticket)
    {
        // For AJAX request
        if (request()->expectsJson() || request()->wantsJson()) {
            return response()->json([
                'ticket' => (new SpinTicketResource($ticket->load(['user', 'spin'])))->resolve(),
            ]);
        }

        $spins = Spin::select('id', 'name')->get();

        return Inertia::render('Admin/SpinTickets/Edit', [
            'ticket' => (new SpinTicketResource($ticket->load(['user', 'spin'])))->resolve(),
            'spins' => $spins,
        ]);
    }


    // app/Http/Controllers/Admin/SpinTicketController.php

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'spin_id' => 'required|exists:spins,id',
            'turns_remaining' => 'required|integer|min:1', // ✅ Min 1 khi create
        ]);

        // ✅ FIXED - Tìm ticket hiện có
        $existingTicket = SpinTicket::where('user_id', $validated['user_id'])
            ->where('spin_id', $validated['spin_id'])
            ->first();

        if ($existingTicket) {
            // ✅ Nếu đã có ticket, CỘNG thêm lượt
            $existingTicket->update([
                'turns_remaining' => $existingTicket->turns_remaining + $validated['turns_remaining']
            ]);

            $ticket = $existingTicket;
        } else {
            // ✅ Nếu chưa có, tạo mới
            $ticket = SpinTicket::create([
                'user_id' => $validated['user_id'],
                'spin_id' => $validated['spin_id'],
                'turns_remaining' => $validated['turns_remaining']
            ]);
        }

        return redirect()->route('admin.spin-tickets.index')
            ->with('success', 'Lượt quay đã được cấp!');
    }

    public function update(Request $request, SpinTicket $ticket)
    {
        $validated = $request->validate([
            'turns_remaining' => 'required|integer|min:0', // ✅ Min 0 khi update (cho phép xóa)
        ]);

        // ✅ FIXED - Update trực tiếp, không dùng DB::raw
        $ticket->update([
            'turns_remaining' => $validated['turns_remaining']
        ]);

        return redirect()->route('admin.spin-tickets.index')
            ->with('success', 'Lượt quay đã được cập nhật!');
    }

    public function bulkGrant(Request $request)
    {
        $validated = $request->validate([
            'spin_id' => 'required|exists:spins,id',
            'user_ids' => 'required|array',
            'user_ids.*' => 'exists:users,id',
            'turns' => 'required|integer|min:1',
        ]);

        $successCount = 0;

        foreach ($validated['user_ids'] as $userId) {
            // ✅ FIXED - Tìm ticket hiện có
            $existingTicket = SpinTicket::where('user_id', $userId)
                ->where('spin_id', $validated['spin_id'])
                ->first();

            if ($existingTicket) {
                // ✅ Nếu đã có, CỘNG thêm
                $existingTicket->update([
                    'turns_remaining' => $existingTicket->turns_remaining + $validated['turns']
                ]);
            } else {
                // ✅ Nếu chưa có, tạo mới
                SpinTicket::create([
                    'user_id' => $userId,
                    'spin_id' => $validated['spin_id'],
                    'turns_remaining' => $validated['turns']
                ]);
            }

            $successCount++;
        }

        return redirect()->route('admin.spin-tickets.index')
            ->with('success', "Đã cấp lượt quay cho {$successCount} người dùng!");
    }
    public function destroy(SpinTicket $ticket)
    {
        $ticket->delete();

        return redirect()->route('admin.spin-tickets.index')
            ->with('success', 'Lượt quay đã được xóa!');
    }
}
