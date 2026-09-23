<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\NroItemGroupSettings;
use Illuminate\Http\Request;

class NroItemGroupController extends Controller
{
    public function show(Request $request)
    {
        abort_unless($request->user()->canViewAllAdminData() || $request->user()->can('nro-sale-policy.manage'), 403);
        return response()->json(NroItemGroupSettings::read())->header('Cache-Control', 'no-store');
    }
    public function update(Request $request)
    {
        abort_unless($request->user()->canViewAllAdminData() || $request->user()->can('nro-sale-policy.manage'), 403);
        try { return response()->json(NroItemGroupSettings::save($request->all())); }
        catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            return response()->json(['message'=>'Cấu hình đang được lưu ở phiên khác. Vui lòng thử lại.'], 423);
        }
    }
}
