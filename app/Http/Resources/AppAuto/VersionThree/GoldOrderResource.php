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
            'gold_bar_template_id'=>(int)($this->gold_bar_template_id??457),'gold_per_bar'=>\App\Services\GoldBarAsset::value($this->gold_bar_template_id),
            'amount_vnd'=>(int)$this->amount_vnd,'price_at_transaction'=>(int)$this->price_at_transaction,
            'status'=>$this->status,'delivery_phase'=>$this->delivery_phase,
            'has_delivery_checkpoint'=>$this->resource->hasDeliveryCheckpoint(),'delivery_data'=>$data,
            'remaining'=>GoldTradingService::remaining($this->resource),'created_at'=>$this->created_at,'completed_at'=>$this->completed_at,
            'cancel_requested_at'=>$this->cancel_requested_at,'refunded_at'=>$this->refunded_at,
        ];
    }
}
