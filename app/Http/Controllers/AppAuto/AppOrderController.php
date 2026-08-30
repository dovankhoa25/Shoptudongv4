<?php

namespace App\Http\Controllers\AppAuto;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;

class AppOrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = Order::query()
            ->when($request->filled('server_id'), fn($q) => $q->where('server_id', $request->server_id))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->where('updated_by', 'web') // 👈 Chỉ lấy đơn web tạo/chỉnh
            ->whereIn('status', ['pending', 'processing']) // Tuỳ logic bạn, thường chỉ lấy đơn chưa xong
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $orders,
        ]);
    }

    public function update(Request $request, Order $order)
    {
        // Chỉ cho phép app cập nhật đơn chưa sync
        if ($order->updated_by !== 'web') {
            return response()->json([
                'success' => false,
                'message' => 'Order is already synced by app.',
            ], 403);
        }

        $request->validate([
            'status' => 'required|in:pending,processing,completed,cancelled',
        ]);

        $order->update([
            'status'      => $request->status,
            'updated_by'  => 'app',
            'last_synced_at' => now(), // nếu có cột này
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order updated successfully',
            'data'    => $order,
        ]);
    }
}
