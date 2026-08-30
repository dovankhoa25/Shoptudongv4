<?php
// app/Http/Resources/GemBotResource.php
namespace App\Http\Resources\GemBots;

use Illuminate\Http\Resources\Json\JsonResource;

class GemBotResource extends JsonResource
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
            'name' => $this->name,
            'account_name' => $this->account_name,
            // Don't expose password in responses
            'has_password' => !empty($this->account_password),
            'server' => $this->whenLoaded('server', function () {
                return [
                    'id' => $this->server->id,
                    'name' => $this->server->name,
                    'name_view' => $this->server->name_view,
                ];
            }),
            'server_game_id' => $this->server_game_id,
            'gem_qty' => $this->gem_qty,
            'gem_qty_formatted' => number_format($this->gem_qty),
            'map_info' => [
                'map_name' => $this->map_name,
                'map_id' => $this->map_id,
                'area_number' => $this->area_number,
                'coordinates' => $this->coordinates,
                'proxy' => $this->proxy,
            ],
            'status' => $this->status,
            'status_label' => $this->status ? 'Hoạt động' : 'Không hoạt động',
            'updated_by' => $this->updated_by,
            'last_synced_at' => $this->last_synced_at,
            'last_synced_at_human' => $this->last_synced_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
