<?php
namespace App\Http\Resources\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class AuthProviderResource extends JsonResource {
    public function toArray(Request $request): array { return [
        'id'=>$this->id, 'provider'=>$this->provider, 'email'=>$this->provider_email,
        'username'=>$this->provider_username, 'avatar'=>$this->avatar,
        'is_enabled'=>$this->is_enabled, 'last_login_at'=>$this->last_login_at?->toISOString(),
        'linked_at'=>$this->created_at?->toISOString(),
    ]; }
}
