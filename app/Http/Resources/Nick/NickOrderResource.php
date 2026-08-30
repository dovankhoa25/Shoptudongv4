<?php

namespace App\Http\Resources\Nick;

use App\Helpers\AccountEncrypt;
use App\Http\Resources\Nick\NickResource;
use App\Http\Resources\User\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NickOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'nick_id'     => $this->nick_id,
            'buyer_id'    => $this->buyer_id,
            'seller_id'   => $this->seller_id,

            'price'       => $this->price,
            'commission'  => $this->commission,
            'status'      => $this->status,


            'created_at'  => $this->created_at,
            'updated_at'  => $this->updated_at,

            // Relations:
            // 'nick' => new NickResource($this->whenLoaded('nick')),
            // 'buyer' => new UserResource($this->whenLoaded('buyer')),
            // 'seller' => new UserResource($this->whenLoaded('seller')),
            'nick' => $this->whenLoaded('nick', function () {
                return [
                    'id' => $this->nick->id,
                    'account_name' => $this->nick->account_name,
                    'account_password' => AccountEncrypt::decrypt($this->nick->account_password),
                    'price' => $this->nick->price,
                    'image'            => $this->nick->image,
                    'attribute_cache_json' => $this->nick->attribute_cache_json,
                    'category' => $this->nick->relationLoaded('category') ? [
                        'id' => $this->nick->category->id,
                        'name' => $this->nick->category->name,
                    ] : null,
                ];
            }),

            'buyer' => $this->whenLoaded('buyer', function () {
                return [
                    'id' => $this->buyer->id,
                    'name' => $this->buyer->username,
                ];
            }),

            'seller' => $this->whenLoaded('seller', function () {
                return [
                    'id' => $this->seller->id,
                    'name' => $this->seller->username,
                ];
            }),
        ];
    }
}
