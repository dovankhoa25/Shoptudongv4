<?php

namespace App\Http\Resources\Order;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'user_id'          => $this->user_id,
            'user_name'        => $this->whenLoaded('user', fn() => $this->user->name),
            'server_id'        => $this->server_id,
            'server_name'      => $this->whenLoaded('server', fn() => $this->server->name),
            'character_name'   => $this->character_name,
            'amount_vnd'       => $this->amount_vnd,
            'gold_qty'         => $this->gold_qty,
            'gold_bar_qty'     => $this->gold_bar_qty,
            'pure_gold_qty'    => $this->pure_gold_qty,
            'price_at_order'   => $this->price_at_order,
            'status'           => $this->status,
            'bot_id'           => $this->bot_id,
            'bot_name'         => $this->whenLoaded('bot', fn() => $this->bot->name),
            'created_at'       => $this->created_at?->toDateTimeString(),
        ];
    }
}
