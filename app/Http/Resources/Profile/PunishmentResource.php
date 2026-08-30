<?php
namespace App\Http\Resources\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class PunishmentResource extends JsonResource {
    public function toArray(Request $request): array { return [
        'id'=>$this->id, 'type'=>$this->type, 'reason'=>$this->reason, 'note'=>$this->note,
        'starts_at'=>$this->starts_at?->toISOString(), 'ends_at'=>$this->ends_at?->toISOString(),
        'is_active'=>$this->is_active, 'revoked_at'=>$this->revoked_at?->toISOString(),
        'revoked_reason'=>$this->revoked_reason, 'created_at'=>$this->created_at?->toISOString(),
    ]; }
}
