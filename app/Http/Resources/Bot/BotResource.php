<?php

namespace App\Http\Resources\Bot;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'account_name' => $this->account_name,
            'account_password' => $this->account_password,
            'has_password' => !empty($this->account_password),
            'type' => $this->type, // Raw string: "selling_main,import_sub"
            'types' => $this->getTypesArray(), // Array: ['selling_main', 'import_sub']
            'type_labels' => $this->getTypeLabels(), // Array: ['Bán chính', 'Nhập phụ']
            'server' => $this->whenLoaded('server', function () {
                return [
                    'id' => $this->server->id,
                    'name' => $this->server->name,
                    'name_view' => $this->server->name_view,
                ];
            }),
            'server_id' => $this->server_id,
            'server_name' => $this->whenLoaded('server', fn() => $this->server->name),
            'server_game_id' => $this->server_game_id,
            'gold_bar_qty' => $this->gold_bar_qty,
            'gold_bar_qty_formatted' => number_format($this->gold_bar_qty, 0, ',', '.'),
            'gold_qty' => $this->gold_qty,
            'gold_qty_formatted' => number_format($this->gold_qty, 0, ',', '.'),
            'map_info' => [
                'map_name' => $this->map_name,
                'map_id' => $this->map_id,
                'area_number' => $this->area_number,
                'coordinates' => $this->coordinates,
                'proxy' => $this->proxy,
            ],
            'map_name' => $this->map_name,
            'map_id' => $this->map_id,
            'area_number' => $this->area_number,
            'coordinates' => $this->coordinates,
            'proxy' => $this->proxy,
            'status' => (bool) $this->status,
            'status_label' => $this->status ? 'Hoạt động' : 'Không hoạt động',
            'status_color' => $this->status ? 'green' : 'red',
            'updated_by' => $this->updated_by,
            'last_synced_at' => $this->last_synced_at?->format('d/m/Y H:i:s'),
            'last_synced_at_human' => $this->last_synced_at?->diffForHumans(),
            'created_at' => $this->created_at?->toDateTimeString(),
            'created_at_human' => $this->created_at?->diffForHumans(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'updated_at_human' => $this->updated_at?->diffForHumans(),
        ];
    }

    /**
     * Get types as array
     */
    private function getTypesArray(): array
    {
        if (empty($this->type)) {
            return [];
        }

        return array_map('trim', explode(',', $this->type));
    }

    /**
     * Get type labels in Vietnamese (array)
     */
    private function getTypeLabels(): array
    {
        $labels = [
            'selling_main' => 'Bán chính',
            'selling_sub' => 'Bán phụ',
            'import_main' => 'Nhập chính',
            'import_sub' => 'Nhập phụ',
            'auto_sell_bar' => 'bán thỏi',
        ];

        $types = $this->getTypesArray();

        return array_map(fn($type) => $labels[$type] ?? $type, $types);
    }
}
