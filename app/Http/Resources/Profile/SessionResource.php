<?php
namespace App\Http\Resources\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class SessionResource extends JsonResource {
    public function toArray(Request $request): array { return [
        'id'=>$this->id, 'device'=>new DeviceResource($this->whenLoaded('device')),
        'ip_address'=>$this->ip_address, 'user_agent'=>$this->user_agent,
        'last_activity_at'=>$this->last_activity_at?->toISOString(), 'expires_at'=>$this->expires_at?->toISOString(),
        'is_revoked'=>$this->is_revoked, 'revoked_at'=>$this->revoked_at?->toISOString(),
        'revoked_reason'=>$this->revoked_reason, 'created_at'=>$this->created_at?->toISOString(),
    ]; }
}
