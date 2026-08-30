<?php

namespace App\Http\Resources\Attribute;

use Illuminate\Http\Resources\Json\JsonResource;

class AttributeResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'options' => $this->options->map(function ($option) {
                return [
                    'id' => $option->id,
                    'option_value' => $option->option_value,
                    'status' => $option->status,
                ];
            }),
        ];
    }
}
