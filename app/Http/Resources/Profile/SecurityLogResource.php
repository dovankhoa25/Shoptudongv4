<?php
namespace App\Http\Resources\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class SecurityLogResource extends JsonResource {
    public function toArray(Request $request): array { return [
        'id'=>$this->id, 'event'=>$this->event, 'ip_address'=>$this->ip_address,
        'user_agent'=>$this->user_agent, 'meta'=>$this->meta, 'created_at'=>$this->created_at?->toISOString(),
    ]; }
}
