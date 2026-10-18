<?php
namespace App\Http\Resources\AppAuto\VersionThree;
use App\Models\ServerGameLogin;
use Illuminate\Http\Resources\Json\JsonResource;
class GemBotResource extends JsonResource {
    public function toArray($request): array {
        $loginId = $this->server_game_id;
        $login = $this->loginServer;
        return [
            'id'=>$this->id, 'server_id'=>(int)$this->server_id, 'server_login_id'=>$loginId ? (int)$loginId : null,
            'server_enabled'=>(bool)$this->server?->status, 'server_name'=>$this->server?->name,
            'login'=>$login ? ['id'=>$login->id,'ip'=>$login->ip,'port'=>(int)$login->port] : null,
            'name'=>$this->name, 'account_name'=>$this->account_name, 'account_password'=>$this->account_password,
            'status'=>(bool)$this->status, 'proxy'=>$this->proxy,
            'map_name'=>$this->map_name, 'map_id'=>(int)$this->map_id, 'area_number'=>(int)$this->area_number, 'coordinates'=>$this->coordinates,
            'gem_qty'=>(int)$this->gem_qty, 'gift_ticket_qty'=>$this->gift_ticket_qty,
            'consign_item_qty'=>$this->consign_item_qty, 'stock_version'=>(int)$this->stock_version,
            'last_synced_at'=>$this->last_synced_at,
        ];
    }
}

