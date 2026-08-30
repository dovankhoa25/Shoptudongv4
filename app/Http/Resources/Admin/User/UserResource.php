<?php

namespace App\Http\Resources\Admin\User;

use App\Policies\UserPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $policy = app(UserPolicy::class);

        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'balance' => $this->balance,
            'avatar' => $this->avatar,
            'status' => $this->status,
            'is_locked' => $this->isLocked(),
            'locked_until' => $this->locked_until,
            'locked_reason' => $this->locked_reason,
            'role' => $this->whenLoaded('roles', fn () => $this->roles->first()?->name),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map->only(['id', 'name'])),
            'email_verified_at' => $this->email_verified_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'can' => [
                'update' => $actor?->can('update', $this->resource) ?? false,
                'lock' => $actor?->can('lock', $this->resource) ?? false,
                'manage_roles' => $actor?->can('updateRolePermission', $this->resource) ?? false,
                'adjust_balance' => $actor
                    ? $policy->adjustBalance($actor, $this->resource)
                    : false,
            ],
        ];
    }
}
