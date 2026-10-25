<?php
namespace App\Http\Resources\AppAuto\VersionThree;
use App\Services\TradingV3\GoldTradingService;
use Illuminate\Http\Resources\Json\JsonResource;
class GoldBotResource extends JsonResource {
    public function toArray($request): array {
        $login=$this->loginServer;
        $stock=$this->resource->relationLoaded('tradingV3ReadStock') ? $this->resource->getRelation('tradingV3ReadStock') : null;
        return [
            'id'=>$this->id,'server_id'=>(int)$this->server_id,'server_login_id'=>$this->server_game_id ? (int)$this->server_game_id : null,
            'server_enabled'=>(bool)$this->server?->status,'server_name'=>$this->server?->name,
            'login'=>$login ? ['id'=>$login->id,'ip'=>$login->ip,'port'=>(int)$login->port] : null,
            'name'=>$this->name,'account_name'=>$this->account_name,'account_password'=>$this->account_password,
            'type'=>$this->type,'types'=>GoldTradingService::types($this->resource),'status'=>(bool)$this->status,'proxy'=>$this->proxy,
            'map_name'=>$this->map_name,'map_id'=>(int)$this->map_id,'area_number'=>(int)$this->area_number,'coordinates'=>$this->coordinates,
            'gold_qty'=>(int)$this->gold_qty,'gold_bar_qty'=>(int)$this->gold_bar_qty,'stock_version'=>(int)$this->stock_version,
            'nrj_gold_bar_qty'=>(int)$this->nrj_gold_bar_qty,
            'radar_enabled'=>(bool)$this->server?->gold_radar_enabled && \App\Services\RadarTradingService::supportedBot($this->resource),
            'radar_qty'=>(int)$this->radar_qty,'bag_capacity'=>$this->bag_capacity,'bag_free_slots'=>$this->bag_free_slots,
            'radar_available'=>$stock['radar_available']??GoldTradingService::available($this->resource,template:281),
            'radar_receive_room'=>$stock['radar_receive_room']??GoldTradingService::receiveRoom($this->resource,template:281),
            'last_synced_at'=>$this->last_synced_at,
            'available'=>$stock['available']??GoldTradingService::available($this->resource),
            'receive_room'=>$stock['receive_room']??GoldTradingService::receiveRoom($this->resource),
            'nrj_available'=>$stock['nrj_available']??GoldTradingService::available($this->resource,template:2032),
            'nrj_receive_room'=>$stock['nrj_receive_room']??GoldTradingService::receiveRoom($this->resource,template:2032),
            'gold_bar_template_id'=>457,'gold_per_bar'=>37000000,'gold_bar_capacity'=>999,
            'gold_bar_assets'=>[['gold_bar_template_id'=>457,'gold_per_bar'=>37000000,'capacity'=>999],['gold_bar_template_id'=>2032,'gold_per_bar'=>195000000,'capacity'=>999]],
        ];
    }
}
