<?php
namespace App\Http\Resources\AppAuto\VersionThree;
use App\Services\TradingV3\GoldTradingService;
use Illuminate\Http\Resources\Json\JsonResource;
class GoldBotResource extends JsonResource {
    public function toArray($request): array {
        $login=$this->loginServer;
        return [
            'id'=>$this->id,'server_id'=>(int)$this->server_id,'server_login_id'=>$this->server_game_id ? (int)$this->server_game_id : null,
            'server_enabled'=>(bool)$this->server?->status,'server_name'=>$this->server?->name,
            'login'=>$login ? ['id'=>$login->id,'ip'=>$login->ip,'port'=>(int)$login->port] : null,
            'name'=>$this->name,'account_name'=>$this->account_name,'account_password'=>$this->account_password,
            'type'=>$this->type,'types'=>GoldTradingService::types($this->resource),'status'=>(bool)$this->status,'proxy'=>$this->proxy,
            'map_name'=>$this->map_name,'map_id'=>(int)$this->map_id,'area_number'=>(int)$this->area_number,'coordinates'=>$this->coordinates,
            'gold_qty'=>(int)$this->gold_qty,'gold_bar_qty'=>(int)$this->gold_bar_qty,'stock_version'=>(int)$this->stock_version,
            'last_synced_at'=>$this->last_synced_at,'v3_worker_id'=>$this->v3_worker_id,'v3_lease_until'=>$this->v3_lease_until,
            'available'=>GoldTradingService::available($this->resource),'receive_room'=>GoldTradingService::receiveRoom($this->resource),
            'gold_bar_template_id'=>457,'gold_per_bar'=>37000000,'gold_bar_capacity'=>999,
        ];
    }
}
