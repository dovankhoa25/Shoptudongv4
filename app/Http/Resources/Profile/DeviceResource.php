<?php
namespace App\Http\Resources\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class DeviceResource extends JsonResource {
    public function toArray(Request $request): array { return [
        'id'=>$this->id, 'device_name'=>$this->device_name, 'platform'=>$this->platform,
        'browser'=>$this->browser, 'ip_address'=>$this->ip_address, 'is_trusted'=>$this->is_trusted,
        'trusted_until'=>$this->trusted_until?->toISOString(), 'last_seen_at'=>$this->last_seen_at?->toISOString(),
        'created_at'=>$this->created_at?->toISOString(),
    ]; }
}
