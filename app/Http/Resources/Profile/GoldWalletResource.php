<?php
namespace App\Http\Resources\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class GoldWalletResource extends JsonResource {
    public function toArray(Request $request): array { return [
        'id'=>$this->id, 'server_id'=>$this->server_id, 'balance'=>$this->balance,
        'locked_balance'=>$this->locked_balance, 'available_balance'=>max(0, $this->balance-$this->locked_balance),
        'status'=>$this->status, 'created_at'=>$this->created_at?->toISOString(), 'updated_at'=>$this->updated_at?->toISOString(),
    ]; }
}
