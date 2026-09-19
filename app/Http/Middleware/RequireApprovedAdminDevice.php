<?php

namespace App\Http\Middleware;

use App\Services\AdminAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RequireApprovedAdminDevice
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('web');
        $service = app(AdminAccessService::class);
        if ($user && ! $request->routeIs('logout') && $service->applies($user)
            && ! $service->approved($user, $request)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Phiên quản trị cần xác minh lại IP và thiết bị.'], 403);
            }

            return redirect()->route('login')->with('status', 'IP hoặc thiết bị quản trị cần được duyệt. Vui lòng đăng nhập để tạo yêu cầu.');
        }

        return $next($request);
    }
}
