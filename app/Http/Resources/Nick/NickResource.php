<?php

namespace App\Http\Resources\Nick;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NickResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     */
    public function toArray($request): array
    {
        return [
            'id'               => $this->id,
            'account_name'     => $this->account_name,
            'account_password' => $this->account_password,
            'price'            => $this->price,
            'description'      => $this->description,
            'image'            => $this->image,
            'listing_type'     => $this->listing_type,
            'status'           => $this->status,
            'attribute_cache_json' => $this->attribute_cache_json,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,

            // Quan hệ: Category
            'category' => $this->whenLoaded('category', function () {
                return [
                    'id'   => $this->category->id,
                    'name' => $this->category->name,
                    'slug' => $this->category->slug,
                ];
            }),

            // // Quan hệ: User
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id'   => $this->user->id,
                    'username' => $this->user->username,
                    'email' => $this->user->email,
                ];
            }),

            // Quan hệ: Attributes
            'attributes' => $this->whenLoaded('attributes', function () {
                return $this->attributes->map(function ($attribute) {
                    return [
                        'id'         => $attribute->id,
                        'name'       => $attribute->name,
                        'option_id'  => $attribute->pivot->attribute_option_id,
                        'option'     => $attribute->options->where('id', $attribute->pivot->attribute_option_id)->first()?->option_value,
                    ];
                });
            }),
        ];
    }
}
