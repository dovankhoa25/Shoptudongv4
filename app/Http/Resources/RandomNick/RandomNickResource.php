<?php

namespace App\Http\Resources\RandomNick;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RandomNickResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'random_box_id' => $this->random_box_id,
            'account' => $this->account,
            'password' => $this->password,
            'description' => $this->description,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'created_at_formatted' => $this->created_at->format('d/m/Y H:i'),
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,

            // Image - ưu tiên ảnh riêng, fallback sang ảnh của random box
            'image_url' => $this->image_url,
            'has_own_image' => !empty($this->getFirstMediaUrl('image')),

            // Status formatting
            'status_text' => $this->status_text,
            'status_color' => $this->status_color,

            // Relationship
            'random_box' => $this->whenLoaded('randomBox', function () {
                return [
                    'id' => $this->randomBox->id,
                    'name' => $this->randomBox->name,
                    'price' => $this->randomBox->price,
                    'price_formatted' => $this->randomBox->price_formatted,
                ];
            }),

            // Additional computed fields
            // 'account_masked' => $this->maskAccount(),
            'password_masked' => $this->maskPassword(),
            'is_available' => $this->isAvailable(),
            'is_taken' => $this->isTaken(),
            'is_deleted' => $this->isDeleted(),
        ];
    }

    /**
     * Mask account for security display
     */
    // private function maskAccount(): string
    // {
    //     $account = $this->account;
    //     $length = strlen($account);

    //     if ($length <= 3) {
    //         return str_repeat('*', $length);
    //     }

    //     return substr($account, 0, 2) . str_repeat('*', $length - 4) . substr($account, -2);
    // }

    // /**
    //  * Mask password for security display
    //  */
    private function maskPassword(): string
    {
        return str_repeat('*', min(strlen($this->password), 8));
    }
}
