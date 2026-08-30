<?php
namespace App\Http\Resources\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class LoginAttemptResource extends JsonResource {
    public function toArray(Request $request): array { return [
        'id'=>$this->id, 'provider'=>$this->provider, 'ip_address'=>$this->ip_address,
        'user_agent'=>$this->user_agent, 'is_success'=>$this->is_success,
        'failure_reason'=>$this->failure_reason, 'created_at'=>$this->created_at?->toISOString(),
    ]; }
}
