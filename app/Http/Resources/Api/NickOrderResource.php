<?php

namespace App\Http\Resources\Api;

use App\Helpers\AccountEncrypt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NickOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'nick_id'    => $this->nick_id,
            'price'      => (int) $this->price,
            'commission' => (int) $this->commission,
            'status'     => $this->status,
            'created_at' => $this->created_at,

            'nick' => [
                'id'           => $this->nick->id,
                'account_name' => $this->nick->account_name,
                'account_password' => AccountEncrypt::decrypt($this->nick->account_password),
                'price'        => (int) $this->nick->price,
                'status'       => $this->nick->status,
                'image_url'    => $this->nick->getFirstMediaUrl('images') ?? null,
                'attribute_cache_json' => $this->nick->attribute_cache_json,
                'description' => $this->nick->description,
            ],

            // 'seller' => [
            //     'id'    => $this->seller->id,
            //     'name'  => $this->seller->name,
            //     'email' => $this->seller->email,
            // ],
            'seller' => [
                'id'    => 'shophhp.vn',
                'name'  => 'shophhp.vn',
                'email' => 'shophhp.vn',
            ],
        ];
    }
}
