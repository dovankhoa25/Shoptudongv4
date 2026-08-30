<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApiUserResource extends JsonResource
{
    /**
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'id'               => (string) $this->id,
            'username'         => $this->username,
            'email'            => $this->email,
            'phone'            => $this->phone ?? null,
            'balance'          => $this->balance ?? null,
            'avatar'           => $this->avatar ?? null,
            'isEmailVerified'  => (bool) $this->hasVerifiedEmail(),
            'created_at'       => $this->created_at,
        ];
    }
}
