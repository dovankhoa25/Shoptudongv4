<?php

namespace App\Http\Controllers\AppAuto;

use App\Http\Controllers\Controller;
use App\Models\Import;
use Illuminate\Http\Request;

class AppImportController extends Controller
{
    public function index(Request $request)
    {
        $imports = Import::query()
            ->when($request->filled('server_id'), fn($q) => $q->where('server_id', $request->server_id))
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->where('updated_by', 'web') // 👈 Chỉ lấy đơn nhập web tạo
            ->whereIn('status', ['pending', 'processing'])
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $imports,
        ]);
    }

    public function update(Request $request, Import $import)
    {
        if ($import->updated_by !== 'web') {
            return response()->json([
                'success' => false,
                'message' => 'Import is already synced by app.',
            ], 403);
        }

        $request->validate([
            'status' => 'required|in:pending,processing,completed,cancelled',
        ]);

        $import->update([
            'status'      => $request->status,
            'updated_by'  => 'app',
            'last_synced_at' => now(), // nếu có cột này
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Import updated successfully',
            'data'    => $import,
        ]);
    }
}
