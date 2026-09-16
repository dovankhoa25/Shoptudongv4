<?php
namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Services\TrafficMonitor;
use App\Services\TrafficMonitorState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class TrafficController extends Controller
{
    public function index(Request $request, TrafficMonitor $monitor)
    {
        $filters = $request->validate([
            'minutes' => 'sometimes|integer|in:1,5,15',
            'ip' => 'nullable|ip',
            'group' => 'nullable|in:api,admin,worker,webhook,web',
            'status' => 'nullable|integer|between:100,599',
        ]);
        return Inertia::render('Admin/Traffic/Index', [
            'traffic' => $monitor->snapshot((int) ($filters['minutes'] ?? 5), $filters['ip'] ?? '', $filters['group'] ?? '', (string) ($filters['status'] ?? '')),
            'filters' => $filters,
            'canManage' => $request->user()->hasRole('super-admin')
                || $request->user()->hasAnyPermission(Permission::TrafficManage->value),
        ]);
    }

    public function update(Request $request, TrafficMonitorState $state): RedirectResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        try {
            $state->setEnabled((bool) $data['enabled']);
        } catch (\Throwable $error) {
            report($error);
            return back()->withErrors(['enabled' => 'Không lưu được trạng thái. Kiểm tra quyền ghi thư mục storage của hosting.']);
        }

        return back()->with('success', $data['enabled'] ? 'Đã bật ghi nhận lưu lượng.' : 'Đã tắt ghi nhận lưu lượng.');
    }
}
