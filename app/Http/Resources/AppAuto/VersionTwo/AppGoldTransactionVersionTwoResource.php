<?php

namespace App\Http\Resources\AppAuto\VersionTwo;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppGoldTransactionVersionTwoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type, // order | import
            // 'user_id' => $this->user_id,
            'character_name' => $this->character_name,
            // 'amount_vnd' => (int) $this->amount_vnd,
            'gold_qty' => (int) $this->gold_qty,
            'gold_bar_qty' => (int) $this->gold_bar_qty,
            'pure_gold_qty' => (int) $this->pure_gold_qty,

            // 'price_at_transaction' => $this->price_at_transaction !== null
            //     ? (float) $this->price_at_transaction
            //     : null,

            'status' => $this->status, // pending | processing | ...

            'bot_id' => $this->bot_id ? (int) $this->bot_id : null,
            // 'updated_by' => $this->updated_by,

            // 'last_synced_at' => $this->last_synced_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,


            // server
            'server_id' => (int) $this->server_id,
            'server_game_id' => (int) 2,
        ];
    }
}
