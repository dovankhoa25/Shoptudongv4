<?php

namespace App\Http\Requests\AppAuto\VersionThree;

use Illuminate\Foundation\Http\FormRequest;

class ActivityBatchRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'worker_id' => ['required', 'uuid'],
            'activities' => ['required', 'array', 'min:1', 'max:2000'],
            'activities.*' => ['array:bot_type,bot_id,code,map_id,zone,x,y,online_seconds,wait_seconds'],
            'activities.*.bot_type' => ['required', 'in:gem_bot,bot'],
            'activities.*.bot_id' => ['required', 'integer', 'min:1'],
            'activities.*.code' => ['required', 'string', 'max:40'],
            'activities.*.map_id' => ['nullable', 'integer', 'min:-1'],
            'activities.*.zone' => ['nullable', 'integer', 'min:-1'],
            'activities.*.x' => ['nullable', 'integer'], 'activities.*.y' => ['nullable', 'integer'],
            'activities.*.online_seconds' => ['nullable', 'integer', 'min:0'],
            'activities.*.wait_seconds' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
