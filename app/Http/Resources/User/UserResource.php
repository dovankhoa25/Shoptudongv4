<?php

namespace App\Http\Resources\User;

use App\Http\Resources\wallets\WalletResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'username'          => $this->username,
            'email'         => $this->email,
            'balance'      => $this->balance,
            'avatar'      => $this->avatar,
            'provider'      => $this->provider,
            'provider_id'      => $this->provider_id,


            'is_locked' => $this->is_locked,
            'locked_reason' => $this->locked_reason,
            'email_verified_at' => $this->email_verified_at,

            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
            // 'roles' => $this->roles->pluck('name'),
            // 'permissions' => $this->whenLoaded('roles', function () {
            //     return $this->roles
            //         ->pluck('permissions')
            //         ->flatten()
            //         ->unique('id')
            //         ->values()
            //         ->map(fn($permission) => [
            //             // 'id' => $permission->id,
            //             'name' => $permission->name,
            //             // 'route' => $permission->route,
            //         ]);
            // }),
        ];
    }
}
