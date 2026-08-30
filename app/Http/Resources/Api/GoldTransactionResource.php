<?php

namespace App\Http\Resources\Api;

use App\Models\GoldTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoldTransactionResource extends JsonResource
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
            'type' => $this->type,
            'user_id' => $this->user_id,
            'server_id' => (int) $this->server_id,
            'server_name' => $this->server?->name_view ?? $this->server?->name,
            'character_name' => $this->character_name,
            'amount_vnd' => (int) $this->amount_vnd,
            'money_amount' => (int) $this->amount_vnd,
            'gold_qty' => (int) $this->gold_qty,
            'gold_bar_qty' => (int) $this->gold_bar_qty,
            'pure_gold_qty' => (int) $this->pure_gold_qty,
            'total_gold' => (int) $this->gold_qty,
            'estimated_gold' => $this->type === GoldTransaction::TYPE_ORDER ? (int) $this->gold_qty : null,
            'price_at_transaction' => (int) $this->price_at_transaction,
            'exchange_rate' => (int) $this->price_at_transaction,
            'status' => $this->status,
            'bot_id' => $this->bot_id,
            'updated_by' => $this->updated_by,
            'last_synced_at' => $this->last_synced_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
