<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Nick;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class NickBulkUpdateController extends Controller
{
    /**
     * Lấy thống kê số lượng nick theo điều kiện filter
     */
    public function getBulkUpdatePreview(Request $request)
    {
        // Check permission
        $user = $request->user();
        if (! $user->canViewAllAdminData()) {
            abort(403, 'Không có quyền lấy danh sách!');
        }

        $query = Nick::query();

        // Filter theo account_name pattern - FIX: xử lý array
        if ($request->filled('account_name_pattern')) {
            $patterns = $request->account_name_pattern;

            // Nếu là array, lấy phần tử đầu tiên
            if (is_array($patterns)) {
                $pattern = $patterns[0] ?? '';
            } else {
                $pattern = $patterns;
            }

            if (! empty($pattern)) {
                $query->where('account_name', 'LIKE', '%'.$pattern.'%');
            }
        }

        // Filter theo user_id (CTV cụ thể)
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter theo category
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Filter theo status hiện tại
        if ($request->filled('current_status')) {
            $query->where('status', $request->current_status);
        }

        // Filter theo listing type
        if ($request->filled('listing_type')) {
            $query->where('listing_type', $request->listing_type);
        }

        // Filter theo khoảng thời gian
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        // Filter theo khoảng giá
        if ($request->filled('price_from')) {
            $query->where('price', '>=', $request->price_from);
        }
        if ($request->filled('price_to')) {
            $query->where('price', '<=', $request->price_to);
        }

        $totalRecords = $query->count();

        // Lấy breakdown theo status
        $statusBreakdown = (clone $query)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->get()
            ->pluck('total', 'status')
            ->toArray();

        // Lấy breakdown theo category
        $categoryBreakdown = (clone $query)
            ->select('category_id', DB::raw('count(*) as total'))
            ->groupBy('category_id')
            ->get()
            ->map(function ($item) {
                $category = Category::find($item->category_id);

                return [
                    'category_id' => $item->category_id,
                    'category_name' => $category?->name ?? 'Chưa phân loại',
                    'total' => $item->total,
                ];
            });

        // Lấy breakdown theo user (CTV)
        $userBreakdown = (clone $query)
            ->select('user_id', DB::raw('count(*) as total'))
            ->groupBy('user_id')
            ->get()
            ->map(function ($item) {
                $ctv = User::find($item->user_id);

                return [
                    'user_id' => $item->user_id,
                    'username' => $ctv?->username ?? 'Unknown',
                    'total' => $item->total,
                ];
            });

        // Lấy một số sample nicks để preview
        $sampleNicks = (clone $query)
            ->with(['category', 'user'])
            ->select('id', 'account_name', 'price', 'status', 'category_id', 'user_id')
            ->limit(10)
            ->get()
            ->map(function ($nick) {
                return [
                    'id' => $nick->id,
                    'account_name' => $nick->account_name,
                    'price' => $nick->price,
                    'status' => $nick->status,
                    'category' => $nick->category?->name ?? 'N/A',
                    'user' => $nick->user?->username ?? 'N/A',
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'total_records' => $totalRecords,
                'status_breakdown' => $statusBreakdown,
                'category_breakdown' => $categoryBreakdown,
                'user_breakdown' => $userBreakdown,
                'sample_nicks' => $sampleNicks,
                'filters_applied' => [
                    'account_name_pattern' => is_array($request->account_name_pattern)
                        ? ($request->account_name_pattern[0] ?? null)
                        : $request->account_name_pattern,
                    'user_id' => $request->user_id,
                    'category_id' => $request->category_id,
                    'current_status' => $request->current_status,
                    'listing_type' => $request->listing_type,
                    'date_from' => $request->date_from,
                    'date_to' => $request->date_to,
                    'price_from' => $request->price_from,
                    'price_to' => $request->price_to,
                ],
            ],
        ]);
    }

    /**
     * Admin bulk update - Cập nhật hàng loạt với giới hạn
     */
    public function adminBulkUpdate(Request $request)
    {

        // Check permission
        $user = $request->user();
        if (! $user->canViewAllAdminData()) {
            abort(403, 'Không có quyền lấy danh sách!');
        }
        $validated = $request->validate([
            'account_name_pattern' => 'nullable|array', // Chấp nhận array
            'account_name_pattern.*' => 'nullable|string',
            'user_id' => 'nullable|exists:users,id',
            'category_id' => 'nullable|exists:categories,id',
            'current_status' => 'nullable|in:not_sold,sold,deleted,return,hide,pending',
            'listing_type' => 'nullable|in:vip,normal',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'price_from' => 'nullable|numeric|min:0',
            'price_to' => 'nullable|numeric|min:0',
            'new_status' => ['required', Rule::in(['not_sold', 'sold', 'deleted', 'return', 'hide', 'pending'])],
            'limit' => 'required|integer|min:1|max:1000',
            'nick_ids' => 'nullable|array',
            'nick_ids.*' => 'exists:nicks,id',
        ]);

        try {
            DB::beginTransaction();

            $query = Nick::query();

            // Nếu có nick_ids cụ thể thì ưu tiên
            if (! empty($validated['nick_ids'])) {
                $query->whereIn('id', $validated['nick_ids']);
            } else {
                // Áp dụng các filter
                if (! empty($validated['account_name_pattern'])) {
                    $patterns = $validated['account_name_pattern'];
                    $pattern = is_array($patterns) ? ($patterns[0] ?? '') : $patterns;

                    if (! empty($pattern)) {
                        $query->where('account_name', 'LIKE', '%'.$pattern.'%');
                    }
                }

                if (! empty($validated['user_id'])) {
                    $query->where('user_id', $validated['user_id']);
                }

                if (! empty($validated['category_id'])) {
                    $query->where('category_id', $validated['category_id']);
                }

                if (! empty($validated['current_status'])) {
                    $query->where('status', $validated['current_status']);
                }

                if (! empty($validated['listing_type'])) {
                    $query->where('listing_type', $validated['listing_type']);
                }

                if (! empty($validated['date_from'])) {
                    $query->whereDate('created_at', '>=', $validated['date_from']);
                }

                if (! empty($validated['date_to'])) {
                    $query->whereDate('created_at', '<=', $validated['date_to']);
                }

                if (! empty($validated['price_from'])) {
                    $query->where('price', '>=', $validated['price_from']);
                }

                if (! empty($validated['price_to'])) {
                    $query->where('price', '<=', $validated['price_to']);
                }
            }

            // Giới hạn số lượng
            $limit = min($validated['limit'], 1000);
            $query->limit($limit);

            // Lấy các ID để update
            $nickIds = $query->pluck('id')->toArray();
            $actualCount = count($nickIds);

            if ($actualCount === 0) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy nick nào phù hợp với điều kiện lọc',
                ], 404);
            }

            // Cập nhật status
            Nick::whereIn('id', $nickIds)->update([
                'status' => $validated['new_status'],
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Đã cập nhật {$actualCount} nick sang trạng thái: ".$this->getStatusLabel($validated['new_status']),
                'data' => [
                    'updated_count' => $actualCount,
                    'new_status' => $validated['new_status'],
                    'nick_ids' => $nickIds,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Bulk update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * CTV single update - Chỉ được update từng nick một
     */
    public function ctvSingleUpdate(Request $request, $nickId)
    {
        $validated = $request->validate([
            'new_status' => ['required', Rule::in(['hide', 'not_sold'])],
        ]);

        try {
            $nick = Nick::where('id', $nickId)
                ->when(! $request->user()->canViewAllAdminData(), fn ($query) => $query->where('user_id', $request->user()->id))
                ->firstOrFail();

            // Kiểm tra logic chuyển đổi
            if ($validated['new_status'] === 'hide' && $nick->status !== 'not_sold') {
                return response()->json([
                    'success' => false,
                    'message' => 'Chỉ có thể ẩn nick đang ở trạng thái "Đang chờ"',
                ], 422);
            }

            if ($validated['new_status'] === 'not_sold' && $nick->status !== 'hide') {
                return response()->json([
                    'success' => false,
                    'message' => 'Chỉ có thể hiện nick đang ở trạng thái "Tạm ẩn"',
                ], 422);
            }

            $oldStatus = $nick->status;
            $nick->update(['status' => $validated['new_status']]);

            return response()->json([
                'success' => true,
                'message' => 'Đã cập nhật trạng thái nick thành công',
                'data' => [
                    'nick_id' => $nick->id,
                    'old_status' => $oldStatus,
                    'new_status' => $nick->status,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy nick hoặc bạn không có quyền chỉnh sửa',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Quick toggle hide/pending cho CTV
     */
    public function ctvQuickToggle(Request $request, $nickId)
    {
        try {
            $nick = Nick::where('id', $nickId)
                ->when(! $request->user()->canViewAllAdminData(), fn ($query) => $query->where('user_id', $request->user()->id))
                ->whereIn('status', ['hide', 'not_sold'])
                ->firstOrFail();

            $oldStatus = $nick->status;
            $newStatus = $nick->status === 'hide' ? 'not_sold' : 'hide';

            $nick->update(['status' => $newStatus]);

            return redirect()->back();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Không thể chuyển đổi trạng thái. Nick không tồn tại hoặc không ở trạng thái hide/pending.',
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Lấy danh sách các account_name pattern phổ biến
     * Ví dụ: nếu có nhiều nick có pattern "NRO_xxx" thì suggest pattern này
     */
    public function getAccountNamePatterns(Request $request)
    {

        $user = $request->user();
        if (! $user->canViewAllAdminData()) {
            abort(403, 'Không có quyền lấy danh sách!');
        }
        // Lấy các prefix phổ biến (3-5 ký tự đầu)
        $patterns = Nick::select(
            DB::raw('SUBSTRING(account_name, 1, 3) as pattern'),
            DB::raw('COUNT(*) as count')
        )
            ->groupBy('pattern')
            ->having('count', '>', 1)
            ->orderBy('count', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($item) {
                return [
                    'pattern' => $item->pattern,
                    'count' => $item->count,
                    'label' => "{$item->pattern}* ({$item->count} nicks)",
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $patterns,
        ]);
    }

    /**
     * Lấy danh sách CTV (users có role CTV)
     * Sử dụng Spatie Permission
     */
    /**
     * Lấy danh sách users theo role (flexible)
     * Có thể filter theo role hoặc lấy tất cả
     */
    /**
     * Lấy danh sách users (CTV/Admin) với filter
     */
    /**
     * Lấy danh sách users (CTV/Admin) với filter
     */
    public function getCTVList(Request $request)
    {

        $user = $request->user();
        if (! $user->canViewAllAdminData()) {
            abort(403, 'Không có quyền lấy danh sách!');
        }
        $users = User::with(['roles', 'categories'])
            ->whereHas('roles')
            ->filter($request->only('search', 'role', 'is_locked'))
            ->withCount([
                'nicks as total_nicks',
                'nicks as pending_nicks' => function ($q) {
                    $q->where('status', 'pending');
                },
                'nicks as hide_nicks' => function ($q) {
                    $q->where('status', 'hide');
                },
                'nicks as sold_nicks' => function ($q) {
                    $q->where('status', 'sold');
                },
                'nicks as not_sold_nicks' => function ($q) {
                    $q->where('status', 'not_sold');
                },
            ])
            ->get()
            ->map(function ($user) {
                $roleNames = $user->roles->pluck('name')->join(', ');

                return [
                    'id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'roles' => $roleNames,
                    'stats' => [
                        'total_nicks' => $user->total_nicks ?? 0,
                        'pending_nicks' => $user->pending_nicks ?? 0,
                        'hide_nicks' => $user->hide_nicks ?? 0,
                        'sold_nicks' => $user->sold_nicks ?? 0,
                        'not_sold_nicks' => $user->not_sold_nicks ?? 0,
                    ],
                    'label' => "{$user->username} - {$user->total_nicks} nicks",
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $users,
        ]);
    }

    /**
     * Lấy danh sách tất cả roles có trong hệ thống
     * Để admin có thể filter theo role khác (không chỉ CTV)
     */
    public function getRolesList(Request $request)
    {

        $user = $request->user();
        if (! $user->canViewAllAdminData()) {
            abort(403, 'Không có quyền lấy danh sách!');
        }
        $roles = Role::select('id', 'name')
            ->withCount(['users'])
            ->get()
            ->map(function ($role) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'users_count' => $role->users_count,
                    'label' => ucfirst($role->name)." ({$role->users_count} users)",
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $roles,
        ]);
    }

    private function getStatusLabel($status)
    {
        $labels = [
            'not_sold' => 'Chưa bán',
            'sold' => 'Đã bán',
            'deleted' => 'Đã xóa',
            'return' => 'Hoàn trả',
            'hide' => 'Tạm ẩn',
            'pending' => 'Đang chờ',
        ];

        return $labels[$status] ?? $status;
    }
}
