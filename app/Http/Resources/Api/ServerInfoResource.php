<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServerInfoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentGoldPrice = $this->goldPrices()->where('status', true)->latest()->first();
        $currentGemPrice = $this->currentGemPrice;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_view' => $this->name_view,
            'status' => $this->status,

            // Giá vàng
            'gold_sell_price' => $currentGoldPrice?->price ?? null, // Giá bán vàng
            'gold_import_price' => $currentGoldPrice?->import_price ?? null, // Giá nhập vàng

            // Giá ngọc
            'gem_multiplier' => $currentGemPrice?->multiplier ?? null, // Hệ số giá ngọc

            // Số lượng ngọc available
            'total_available_gems' => $this->getTotalAvailableGems(),

            // Format hiển thị cho popup
            'formatted_gold_sell_price' => $currentGoldPrice ? 'x' . number_format($currentGoldPrice->price) : null,
            'formatted_gold_import_price' => $currentGoldPrice ? 'x' . number_format($currentGoldPrice->import_price) : null,
            'formatted_gem_price' => $currentGemPrice ? 'x' . number_format($currentGemPrice->multiplier, 1, '.', ',') : null,
        ];
    }
}
