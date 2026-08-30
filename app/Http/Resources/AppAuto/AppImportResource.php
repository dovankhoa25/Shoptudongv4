<?php

namespace App\Http\Resources\AppAuto;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppImportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'user_id'             => $this->user_id,
            'server_id'           => $this->server_id,
            'server_name'         => $this->server?->name,
            'character_name'      => $this->character_name,
            'amount_vnd'          => $this->amount_vnd,
            'gold_qty'            => $this->gold_qty,
            'gold_bar_qty'        => $this->gold_bar_qty,
            'pure_gold_qty'       => $this->pure_gold_qty,
            'import_price_at_order' => $this->import_price_at_order,
            'status'              => $this->status,
            'bot_id'              => $this->bot_id,
            'bot_name'            => $this->bot?->name,
            'created_at'          => $this->created_at,
            'updated_at'          => $this->updated_at,
        ];
    }
}
