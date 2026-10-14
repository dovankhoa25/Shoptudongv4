<?php
namespace App\Http\Resources\AppAuto\VersionThree;
use App\Services\TradingV3\GoldTradingService;
use Illuminate\Http\Resources\Json\JsonResource;
class GoldOrderResource extends JsonResource {
    public function toArray($request): array {
        $data=$this->delivery_data??[];$data['progress']??=['bars'=>0,'pure'=>0];
        return [
            'id'=>$this->id,'type'=>$this->type,'server_id'=>(int)$this->server_id,'bot_id'=>$this->bot_id ? (int)$this->bot_id : null,
            'character_name'=>$this->character_name,'character_id'=>$this->character_id,
            'gold_qty'=>(int)$this->gold_qty,'gold_bar_qty'=>(int)$this->gold_bar_qty,'bars_qty'=>(int)$this->gold_bar_qty,'pure_gold_qty'=>(int)$this->pure_gold_qty,
            'amount_vnd'=>(int)$this->amount_vnd,'price_at_transaction'=>(int)$this->price_at_transaction,
            'status'=>$this->status,'delivery_phase'=>$this->delivery_phase,'processing_version'=>$this->processing_version,
            'processing_owner'=>$this->processing_owner,'processing_token'=>$this->processing_token,'delivery_data'=>$data,
            'remaining'=>GoldTradingService::remaining($this->resource),'created_at'=>$this->created_at,'completed_at'=>$this->completed_at,
            'cancel_requested_at'=>$this->cancel_requested_at,'refunded_at'=>$this->refunded_at,
        ];
    }
}
