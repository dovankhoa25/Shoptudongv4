<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GemTransactionResource extends JsonResource
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
            'user_id' => $this->user_id,
            'server_id' => (int) $this->server_id,
            'server_name' => $this->server?->name_view ?? $this->server?->name,
            'character_name' => $this->character_name,
            'amount_vnd' => (int) $this->amount_vnd,
            'gem_qty' => (int) $this->gem_qty,
            'price_at_transaction' => $this->price_at_transaction,
            'status' => $this->status,
            'updated_by' => $this->updated_by,
            'last_synced_at' => $this->last_synced_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
