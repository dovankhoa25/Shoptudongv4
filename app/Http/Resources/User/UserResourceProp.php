<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResourceProp extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'username'               => $this->username,
            'email'              => $this->email,
            'balance'            => (int) $this->balance,
            'avatar'             => $this->avatar ? asset('storage/' . $this->avatar) : null,
            'provider'           => $this->provider,
            'provider_id'        => $this->provider_id,
            'is_locked'          => (bool) $this->is_locked,
            'locked_reason'      => $this->locked_reason,
            'email_verified_at'  => $this->email_verified_at,
            'created_at'         => $this->created_at ? $this->created_at : null,
            'updated_at'         => $this->updated_at ? $this->updated_at : null,
            'check_user_demo'  => $this->email_verified_at,

        ];
    }
}
