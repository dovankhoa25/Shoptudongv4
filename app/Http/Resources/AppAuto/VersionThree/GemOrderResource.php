<?php
namespace App\Http\Resources\AppAuto\VersionThree;
use Illuminate\Http\Resources\Json\JsonResource;
class GemOrderResource extends JsonResource {
    public function toArray($request): array {
        return [
            'id'=>$this->id,'server_id'=>(int)$this->server_id,'bot_id'=>$this->bot_id ? (int)$this->bot_id : null,
            'character_name'=>$this->character_name,'character_id'=>$this->character_id,'item'=>$this->item,
            'gem_qty'=>(int)$this->gem_qty,'delivery_method'=>$this->delivery_method,
            'status'=>$this->status,'delivery_phase'=>$this->delivery_phase,
            'has_delivery_checkpoint'=>$this->resource->hasDeliveryCheckpoint(),'delivery_data'=>$this->delivery_data,
            'delivered_gem_qty'=>(int)$this->delivered_gem_qty,'bot_gem_spent_qty'=>(int)$this->bot_gem_spent_qty,
            'tickets_used_qty'=>(int)$this->tickets_used_qty,'created_at'=>$this->created_at,
            'completed_at'=>$this->completed_at,'cancel_requested_at'=>$this->cancel_requested_at,'refunded_at'=>$this->refunded_at,
        ];
    }
}

