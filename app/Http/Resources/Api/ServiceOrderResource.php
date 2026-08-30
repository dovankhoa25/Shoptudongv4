<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'user_id' => $this->user_id,
            'receiver_id' => $this->receiver_id,
            'service_price' => $this->service_price,
            'account' => $this->account,
            'password' => $this->password,
            'description' => $this->description,
            'field_values_json' => $this->field_values_json,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Relations
            'service_name' => $this->service->name ?? null,
            'service' => $this->service ? [
                'id' => $this->service->id,
                'name' => $this->service->name,
                'processing_time' => $this->service->processing_time,
                'warranty' => $this->service->warranty,
            ] : null,
        ];
    }
}
