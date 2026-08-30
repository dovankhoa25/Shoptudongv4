<?php

namespace App\Http\Resources\BotHistory;

use Illuminate\Http\Resources\Json\JsonResource;

class BotHistoryResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'               => $this->id,
            'entity_type'      => $this->entity_type,
            'entity_id'        => $this->entity_id,
            'action'           => $this->action,
            'source'           => $this->source,
            'category'         => $this->category,
            'old_data'         => $this->old_data,
            'new_data'         => $this->new_data,
            'changed_fields'   => $this->changed_fields,
            'note'             => $this->note,
            'ip_address'       => $this->ip_address,
            'user_agent'       => $this->user_agent,
            'transaction_id'   => $this->transaction_id,
            'transaction_type' => $this->transaction_type,
            'entity_info'      => $this->entity_info ?? null,
            'admin_user'       => $this->whenLoaded('adminUser', fn() => [
                'id'       => $this->adminUser->id,
                'username' => $this->adminUser->username,
            ]),
            'created_at'       => $this->created_at->format('Y-m-d H:i:s'),
            'created_at_human' => $this->created_at->diffForHumans(),
        ];
    }
}
