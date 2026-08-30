<?php

namespace App\Http\Resources\AppAuto\VersionTwo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppGemTransactionVersionTwoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     */
    public function toArray($request): array
    {
        return [
            'id'                    => $this->id,
            // 'user_id'              => $this->user_id,
            // 'user_name'            => $this->user?->name, // nếu có quan hệ user
            // 'price_at_transaction' => $this->price_at_transaction,


            'character_name'       => $this->character_name,
            'item'       => $this->item,
            // 'amount_vnd'           => $this->amount_vnd,
            'gem_qty'              => (int) $this->gem_qty,

            'status'               => $this->status,
            'updated_by'           => $this->updated_by,
            'bot_id' => null,
            // 'last_synced_at'       => $this->last_synced_at,


            'created_at'           => $this->created_at,
            'updated_at'           => $this->updated_at,


            // server
            'server_id' =>  (int) $this->server_id,
            'server_game_id' =>  (int) $this->server_game_id ?? $this->server?->server_game_id,
        ];
    }
}
