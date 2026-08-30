<?php
namespace App\Http\Resources\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class NroAccountResource extends JsonResource {
    public function toArray(Request $request): array { return [
        'id'=>$this->id, 'account_name'=>$this->account_name, 'server'=>$this->server,
        'character_name'=>$this->character_name, 'status'=>$this->status,
        'locked_reason'=>$this->locked_reason, 'locked_until'=>$this->locked_until?->toISOString(),
        'created_at'=>$this->created_at?->toISOString(), 'updated_at'=>$this->updated_at?->toISOString(),
    ]; }
}
