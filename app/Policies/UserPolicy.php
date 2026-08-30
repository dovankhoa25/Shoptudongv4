<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    protected function getRoleLevel(User $user): int
    {
        return $user->roleLevel();
    }

    /**
     * Chỉ cho người cấp cao hơn được chỉnh quyền.
     */
    private function canManage(User $authUser, User $targetUser): bool
    {
        return $authUser->id !== $targetUser->id
            && $this->getRoleLevel($authUser) > $this->getRoleLevel($targetUser);
    }

    public function updateRolePermission(User $authUser, User $targetUser): bool
    {
        return $authUser->can(Permission::UsersManageRoles->value)
            && $this->canManage($authUser, $targetUser);
    }

    public function lock(User $authUser, User $targetUser): bool
    {
        return $authUser->can(Permission::UsersLock->value)
            && $this->canManage($authUser, $targetUser);
    }

    public function adjustBalance(User $authUser, User $targetUser): bool
    {
        return ($authUser->hasRole('super-admin')
                || $authUser->can(Permission::UsersAdjustBalance->value))
            && $this->canManage($authUser, $targetUser);
    }

    public function viewPermissions(User $authUser, User $targetUser): bool
    {
        return $authUser->can(Permission::UsersManageRoles->value)
            && $this->canManage($authUser, $targetUser);
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $user->can(Permission::UsersUpdate->value)
            && $this->canManage($user, $model);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
