<?php
namespace App\Http\Resources\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class GoldTransactionResource extends JsonResource {
    public function toArray(Request $request): array { return [
        'id'=>$this->id, 'type'=>$this->type, 'server_id'=>$this->server_id,
        'character_name'=>$this->character_name, 'amount_vnd'=>$this->amount_vnd,
        'gold_qty'=>$this->gold_qty, 'gold_bar_qty'=>$this->gold_bar_qty, 'pure_gold_qty'=>$this->pure_gold_qty,
        'gold_bar_template_id'=>(int)($this->gold_bar_template_id??457),'gold_per_bar'=>\App\Services\GoldBarAsset::value($this->gold_bar_template_id),
        'price_at_transaction'=>$this->price_at_transaction, 'status'=>$this->status,
        'delivery_phase'=>$this->delivery_phase,'delivery_data'=>['progress'=>$this->delivery_data['progress']??['bars'=>0,'pure'=>0]],
        'remaining'=>\App\Services\TradingV3\GoldTradingService::remaining($this->resource),
        'last_synced_at'=>$this->last_synced_at?->toISOString(), 'created_at'=>$this->created_at?->toISOString(),
    ]; }
}
