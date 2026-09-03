<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission as AppPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdjustUserBalanceRequest;
use App\Http\Resources\Admin\User\UserCollection;
use App\Http\Resources\Admin\User\UserResource;
use App\Http\Resources\User\CtvResource;
use App\Models\User;
use App\Models\UserAuthProvider;
use App\Services\TransactionService;
use App\Support\AdminTableSearch;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'role', 'is_locked']);
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        $users = User::query()
            ->with('roles:id,name')
            ->when($filters['search'] ?? null, function ($query, string $search) {
                AdminTableSearch::applyPreset($query, $search, 'users');
            })
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->whereHas('roles', fn ($query) => $query->where('name', $role)))
            ->when(array_key_exists('is_locked', $filters), function ($query) use ($filters) {
                if ((int) $filters['is_locked'] === 1) {
                    $query->whereIn('status', [User::STATUS_LOCKED, User::STATUS_BANNED]);
                } else {
                    $query->where('status', User::STATUS_ACTIVE);
                }
            })
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('Admin/Users/Index', [
            'users' => new UserCollection($users),
            'filters' => $filters,
            'can' => [
                'create' => $request->user()->hasRole('super-admin')
                    || $request->user()->can(AppPermission::UsersCreate->value),
            ],
        ]);
    }

    public function ctv(Request $request): Response
    {
        $filters = $request->only(['search', 'role', 'is_locked']);
        $perPage = min(max($request->integer('per_page', 15), 1), 100);

        $query = User::query()
            ->with(['roles:id,name', 'categories'])
            ->whereHas('roles')
            ->filter($filters);

        $statistics = [
            'total_ctv' => (clone $query)->count(),
            'active_ctv' => (clone $query)->where('status', User::STATUS_ACTIVE)->count(),
            'locked_ctv' => (clone $query)->whereIn('status', [User::STATUS_LOCKED, User::STATUS_BANNED])->count(),
            'total_balance' => (clone $query)->sum('balance'),
            'average_balance' => (clone $query)->avg('balance'),
            'ctv_with_categories' => (clone $query)->has('categories')->count(),
        ];

        $users = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/CongTacVien/Index', [
            'congTacViens' => CtvResource::collection($users),
            'statistics' => $statistics,
            'filters' => $filters,
        ]);
    }

    public function store(Request $request, TransactionService $transactions): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:191', 'unique:users,username'],
            'email' => ['nullable', 'string', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'avatar' => ['nullable', 'string', 'max:2048'],
            'balance' => ['nullable', 'integer', 'min:0', 'max:'.TransactionService::MAX_BALANCE],
        ]);

        $initialBalance = (int) ($data['balance'] ?? 0);
        $actor = $request->user();

        if ($initialBalance > 0) {
            abort_unless(
                $actor->hasRole('super-admin')
                    || $actor->can(AppPermission::UsersAdjustBalance->value),
                403,
                'Bạn không có quyền cấp số dư ban đầu.',
            );
        }

        $user = DB::transaction(function () use ($data, $initialBalance, $actor, $request, $transactions): User {
            $user = User::create([
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
                'avatar' => $data['avatar'] ?? null,
                'balance' => 0,
                'status' => User::STATUS_ACTIVE,
            ]);

            UserAuthProvider::create([
                'user_id' => $user->id,
                'provider' => 'password',
                'provider_id' => $user->username,
                'provider_email' => $user->email,
                'provider_username' => $user->username,
            ]);

            if ($initialBalance > 0) {
                $transactions->adjustBalance(
                    $user,
                    $actor,
                    'credit',
                    $initialBalance,
                    'Cấp số dư ban đầu khi tạo tài khoản.',
                    (string) Str::uuid(),
                    $this->requestMetadata($request),
                );
            }

            return $user;
        });
        $user->refresh();

        return response()->json([
            'message' => 'Tạo người dùng thành công.',
            'user' => new UserResource($user),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'username' => ['required', 'string', 'max:191', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['nullable', 'string', 'email', 'max:191', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:6'],
            'avatar' => ['nullable', 'string', 'max:2048'],
        ]);

        $user->fill([
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
            'avatar' => $data['avatar'] ?? null,
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();

        // Giữ đồng bộ hồ sơ đăng nhập bằng mật khẩu nếu username/email thay đổi
        $passwordProvider = $user->authProviders()->where('provider', 'password')->first();
        if ($passwordProvider) {
            $passwordProvider->forceFill([
                'provider_id' => $user->username,
                'provider_email' => $user->email,
                'provider_username' => $user->username,
            ])->save();
        }

        return response()->json([
            'message' => 'Cập nhật người dùng thành công.',
            'user' => new UserResource($user),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:191'],
        ]);
        $search = trim($data['q'] ?? '');

        if (mb_strlen($search) < 2) {
            return response()->json(['users' => []]);
        }

        $users = User::query()
            ->where(function ($query) use ($search) {
                $query->where('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
            ->limit(10)
            ->get(['id', 'username', 'email', 'balance', 'avatar']);

        return response()->json(['users' => $users]);
    }

    public function getPermissions(User $user): JsonResponse
    {
        $this->authorize('viewPermissions', $user);

        $actor = request()->user();
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->select('id', 'name')
            ->orderBy('name')
            ->get();
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', AppPermission::values())
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        if (! $actor->hasRole('super-admin')) {
            $roles = $roles->filter(
                fn (Role $role) => User::roleLevelFor($role->name) < $actor->roleLevel(),
            )->values();
            $allowedPermissionIds = $actor->getAllPermissions()->pluck('id');
            $permissions = $permissions->whereIn('id', $allowedPermissionIds)->values();
        }

        return response()->json([
            'all_roles' => $roles,
            'all_permissions' => $permissions,
            'user_roles' => $user->roles()->pluck('roles.id'),
            'user_permissions' => $user->permissions()
                ->whereIn('name', AppPermission::values())
                ->pluck('permissions.id'),
        ]);
    }

    public function assign(Request $request, User $user): JsonResponse
    {
        $this->authorize('updateRolePermission', $user);

        $data = $request->validate([
            'roles' => ['sometimes', 'array'],
            'roles.*' => [
                'integer',
                Rule::exists('roles', 'id')->where('guard_name', 'web'),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => [
                'integer',
                Rule::exists('permissions', 'id')->where(
                    fn ($query) => $query
                        ->where('guard_name', 'web')
                        ->whereIn('name', AppPermission::values()),
                ),
            ],
        ]);

        $actor = $request->user();
        if (! $actor->hasRole('super-admin')) {
            $allowedRoleIds = Role::query()
                ->get(['id', 'name'])
                ->filter(fn (Role $role) => User::roleLevelFor($role->name) < $actor->roleLevel())
                ->pluck('id');
            $allowedPermissionIds = $actor->getAllPermissions()
                ->whereIn('name', AppPermission::values())
                ->pluck('id');

            abort_if(collect($data['roles'] ?? [])->diff($allowedRoleIds)->isNotEmpty(), 403, 'Bạn không thể cấp role cao hơn role của mình.');
            abort_if(collect($data['permissions'] ?? [])->diff($allowedPermissionIds)->isNotEmpty(), 403, 'Bạn không thể cấp quyền mình không sở hữu.');
        }

        if (array_key_exists('roles', $data)) {
            $user->syncRoles($data['roles']);
        }

        if (array_key_exists('permissions', $data)) {
            $user->syncPermissions($data['permissions']);
        }

        return response()->json(['message' => 'Cập nhật vai trò và quyền thành công.']);
    }

    public function lockUser(Request $request, User $user): JsonResponse
    {
        $this->authorize('lock', $user);

        $data = $request->validate([
            'type' => ['required', Rule::in([
                User::STATUS_ACTIVE,
                User::STATUS_LOCKED,
                User::STATUS_BANNED,
            ])],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($data['type'] === User::STATUS_ACTIVE) {
            return $this->unlockUser($user);
        }

        $user->forceFill([
            'status' => $data['type'],
            'locked_until' => null,
            'locked_reason' => $data['reason'],
            'locked_by' => $request->user()->id,
        ])->save();

        return response()->json(['message' => 'Đã khóa tài khoản.']);
    }

    public function unlockUser(User $user): JsonResponse
    {
        $this->authorize('lock', $user);

        $user->forceFill([
            'status' => User::STATUS_ACTIVE,
            'locked_until' => null,
            'locked_reason' => null,
            'locked_by' => null,
        ])->save();

        return response()->json(['message' => 'Đã mở khóa tài khoản.']);
    }

    public function adjustBalance(
        AdjustUserBalanceRequest $request,
        User $user,
        TransactionService $transactions,
    ): JsonResponse {
        $this->authorize('adjustBalance', $user);
        $data = $request->validated();

        $result = $transactions->adjustBalance(
            $user,
            $request->user(),
            $data['direction'],
            (int) $data['amount'],
            $data['description'],
            $data['idempotency_key'],
            $this->requestMetadata($request),
        );
        $transaction = $result['transaction'];

        return response()->json([
            'message' => $result['created']
                ? 'Điều chỉnh số dư thành công.'
                : 'Giao dịch đã được xử lý trước đó.',
            'balance' => $transaction->balance_after,
            'transaction_id' => $transaction->id,
        ]);
    }

    /** @return array{ip_address: string|null, user_agent: string|null} */
    private function requestMetadata(Request $request): array
    {
        return [
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ];
    }
}
