<?php

// app/Http/Resources/GemPrice/GemPriceResource.php
namespace App\Http\Resources\GemPrice;

use Illuminate\Http\Resources\Json\JsonResource;

class GemPriceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'server' => [
                'id' => $this->server_id,
                'name' => $this->whenLoaded('server', function () {
                    return $this->server->name;
                }),
            ],
            'multiplier' => $this->multiplier,
            'multiplier_display' => $this->multiplier_display, // x13, x13.5
            'gems_per_10k' => $this->gems_per_base, // 130, 135
            'gems_per_10k_formatted' => number_format($this->gems_per_base) . ' ngọc/10k',
            'status' => $this->status,
            'status_label' => $this->status ? 'Đang áp dụng' : 'Không áp dụng',
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'created_at_human' => $this->created_at->diffForHumans(),
            'updated_at_human' => $this->updated_at->diffForHumans(),
        ];
    }
}
