<?php
namespace App\Http\Resources\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class GoldTransactionResource extends JsonResource {
    public function toArray(Request $request): array { return [
        'id'=>$this->id, 'type'=>$this->type, 'server_id'=>$this->server_id,
        'character_name'=>$this->character_name, 'amount_vnd'=>$this->amount_vnd,
        'gold_qty'=>$this->gold_qty, 'gold_bar_qty'=>$this->gold_bar_qty, 'pure_gold_qty'=>$this->pure_gold_qty,
        'price_at_transaction'=>$this->price_at_transaction, 'status'=>$this->status,
        'last_synced_at'=>$this->last_synced_at?->toISOString(), 'created_at'=>$this->created_at?->toISOString(),
    ]; }
}
