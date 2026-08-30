<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Nick;
use App\Models\User;

class NickPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->canViewAllAdminData()
            || $user->can(Permission::NicksView->value)
            || $user->can(Permission::NicksManage->value);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Nick $nick): bool
    {
        return $user->canViewAllAdminData() || $this->owns($user, $nick);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->canViewAllAdminData()
            || $user->can(Permission::NicksManage->value);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Nick $nick): bool
    {
        return $user->canViewAllAdminData()
            || ($user->can(Permission::NicksManage->value) && $this->owns($user, $nick));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Nick $nick): bool
    {
        return $this->update($user, $nick);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Nick $nick): bool
    {
        return $this->update($user, $nick);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Nick $nick): bool
    {
        return $this->update($user, $nick);
    }

    private function owns(User $user, Nick $nick): bool
    {
        return (int) $nick->user_id === (int) $user->id;
    }
}
