<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission as AppPermission;
use App\Http\Controllers\Controller;
use App\Http\Resources\Role\RoleResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission as ModelsPermission;
use Spatie\Permission\Models\Role as ModelsRole;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = $validated['per_page'] ?? 20;

        $query = ModelsRole::query()
            ->where('guard_name', 'web')
            ->withCount([
                'permissions' => fn ($query) => $query->whereIn('name', AppPermission::values()),
                'users',
            ]);

        if ($request->filled('search')) {
            $searchTerm = $request->input('search');
            $query->where('name', 'like', "%{$searchTerm}%");
        }

        $roles = $query->paginate($perPage)->withQueryString();
        $actor = $request->user();
        $actorLevel = $actor->roleLevel();
        $isSuperAdmin = $actor->hasRole('super-admin');

        $roles->getCollection()->each(function (ModelsRole $role) use ($actorLevel, $isSuperAdmin): void {
            $role->setAttribute(
                'can_manage',
                $isSuperAdmin || User::roleLevelFor($role->name) < $actorLevel,
            );
        });

        return Inertia::render('Admin/Role/Index', [
            'roles' => RoleResource::collection($roles),
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => [
                'required',
                'string',
                'max:166',
                Rule::unique('roles', 'name')->where('guard_name', 'web'),
            ],
            'guard_name' => ['required', Rule::in(['web'])],
        ]);

        $this->assertRoleNameCanBeManaged($request, $validatedData['name']);

        ModelsRole::create($validatedData);

        return Redirect::route('admin.roles.index')->with('success', 'Role created.');
    }

    public function show(string $id)
    {
        $role = ModelsRole::query()
            ->where('guard_name', 'web')
            ->with('permissions')
            ->findOrFail($id);

        return response()->json([
            'data' => new RoleResource($role),
        ]);
    }

    public function update(Request $request, string $id)
    {
        $role = ModelsRole::query()->where('guard_name', 'web')->findOrFail($id);
        $this->assertRoleCanBeManaged($request, $role);

        $validatedData = $request->validate([
            'name' => [
                'required',
                'string',
                'max:166',
                Rule::unique('roles', 'name')
                    ->where('guard_name', 'web')
                    ->ignore($role->id),
            ],
        ]);

        $this->assertRoleNameCanBeManaged($request, $validatedData['name']);

        $role->update($validatedData);

        return Redirect::route('admin.roles.index')->with('success', 'Role updated.');
    }

    public function destroy(Request $request, string $id)
    {
        $role = ModelsRole::query()->where('guard_name', 'web')->findOrFail($id);
        $this->assertRoleCanBeManaged($request, $role);
        $role->delete();

        return response()->json(null, 204);
    }

    public function getPermissions(Request $request, string $id): JsonResponse
    {
        $role = ModelsRole::query()->where('guard_name', 'web')->findOrFail($id);
        $this->assertRoleCanBeManaged($request, $role);

        $permissions = $this->assignablePermissions($request->user());
        $assignableIds = $permissions->pluck('id');

        $roleSemanticPermissions = $role->permissions()
            ->whereIn('name', AppPermission::values())
            ->select('permissions.id', 'permissions.name')
            ->orderBy('permissions.name')
            ->get();

        return response()->json([
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
            ],
            'all_permissions' => $permissions,
            // Modal chỉ được gửi lại những quyền mà người thao tác có thể quản lý.
            'role_permissions' => $roleSemanticPermissions
                ->whereIn('id', $assignableIds)
                ->pluck('id')
                ->values(),
            // Các quyền này chỉ để hiển thị; khi lưu controller luôn giữ nguyên.
            'locked_permissions' => $roleSemanticPermissions
                ->whereNotIn('id', $assignableIds)
                ->values(),
        ]);
    }

    public function updatePermissions(Request $request, string $id): JsonResponse
    {
        $role = ModelsRole::query()->where('guard_name', 'web')->findOrFail($id);
        $this->assertRoleCanBeManaged($request, $role);

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => [
                'integer',
                'distinct',
                Rule::exists('permissions', 'id')->where(
                    fn ($query) => $query
                        ->where('guard_name', 'web')
                        ->whereIn('name', AppPermission::values()),
                ),
            ],
        ]);
        $permissionIds = collect($data['permissions'])
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $assignablePermissions = $this->assignablePermissions($request->user());
        $assignableIds = $assignablePermissions->pluck('id');

        abort_if(
            $permissionIds->diff($assignableIds)->isNotEmpty(),
            403,
            'Bạn không thể cấp quyền mình không sở hữu.',
        );

        $selectedPermissions = $assignablePermissions
            ->whereIn('id', $permissionIds)
            ->values();

        // Giữ lại quyền route cũ và quyền semantic nằm ngoài phạm vi của người thao tác.
        // Nếu không làm vậy, syncPermissions() sẽ âm thầm xóa các quyền modal không hiển thị.
        $preservedPermissions = $role->permissions()
            ->where(function ($query) use ($assignableIds): void {
                $query
                    ->whereNotIn('name', AppPermission::values())
                    ->orWhereNotIn('permissions.id', $assignableIds);
            })
            ->get();

        DB::transaction(function () use ($role, $preservedPermissions, $selectedPermissions): void {
            $role->syncPermissions(
                $preservedPermissions
                    ->concat($selectedPermissions)
                    ->unique('id')
                    ->values(),
            );
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $rolePermissionIds = $role->permissions()
            ->whereIn('name', AppPermission::values())
            ->whereIn('permissions.id', $assignableIds)
            ->pluck('permissions.id')
            ->values();

        return response()->json([
            'message' => 'Đã cập nhật quyền cho vai trò.',
            'role_permissions' => $rolePermissionIds,
            'permissions_count' => $role->permissions()
                ->whereIn('name', AppPermission::values())
                ->count(),
        ]);
    }

    /** @return Collection<int, ModelsPermission> */
    private function assignablePermissions(User $user): Collection
    {
        $query = ModelsPermission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', AppPermission::values())
            ->select('id', 'name', 'guard_name')
            ->orderBy('name');

        if (! $user->hasRole('super-admin')) {
            $query->whereIn('id', $user->getAllPermissions()->pluck('id'));
        }

        return $query->get();
    }

    private function assertRoleCanBeManaged(Request $request, ModelsRole $role): void
    {
        if ($request->user()->hasRole('super-admin')) {
            return;
        }

        abort_if(
            User::roleLevelFor($role->name) >= $request->user()->roleLevel(),
            403,
            'Bạn không thể sửa role ngang hoặc cao hơn role của mình.',
        );
    }

    private function assertRoleNameCanBeManaged(Request $request, string $roleName): void
    {
        if ($request->user()->hasRole('super-admin')) {
            return;
        }

        abort_if(
            User::roleLevelFor($roleName) >= $request->user()->roleLevel(),
            403,
            'Bạn không thể tạo hoặc đổi thành role ngang/cao hơn role của mình.',
        );
    }
}
