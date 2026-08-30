<?php

namespace App\Http\Resources\ServiceOrder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReceiverServiceOrderResource extends JsonResource
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
            'service_price' => $this->service_price,
            'account' => $this->account,
            'password' => $this->password,
            'description' => $this->description,
            'field_values_json' => $this->field_values_json,
            'status' => $this->status,

            'user' => [
                'id' => $this->user->id ?? null,
                'username' => $this->user->username ?? null,
            ],
            'receiver' => [
                'id' => $this->receiver->id ?? null,
                'username' => $this->receiver->username ?? null,
            ],

            'service' => $this->service ? [
                'id' => $this->service->id,
                'name' => $this->service->name,
                'processing_time' => $this->service->processing_time,
                'warranty' => $this->service->warranty,
            ] : null,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
