<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\TrafficMonitor;
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
        ]);
    }
}
