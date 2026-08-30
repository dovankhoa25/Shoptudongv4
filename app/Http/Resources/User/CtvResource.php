<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CtvResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'    => $this->id,
            'username'  => $this->username,
            'email' => $this->email,
            'balance'      => $this->balance,
            'avatar'      => $this->avatar,
            'created_at'      => $this->created_at,
            'roles' => $this->roles->pluck('name'),
            'categories' => $this->categories->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'can_post' => (bool) $category->pivot->can_post ?? false,
                ];
            })->toArray(),
        ];
    }
}
