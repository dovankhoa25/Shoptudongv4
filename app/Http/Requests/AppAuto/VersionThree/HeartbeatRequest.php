<?php

namespace App\Http\Requests\AppAuto\VersionThree;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'worker_id' => ['required', 'uuid'],
            'single_tool_mode' => ['sometimes', 'accepted'],
            'gem_timeout_policy' => ['sometimes', 'array:enabled,timeout_minutes,refund_grace_minutes'],
            'gem_timeout_policy.enabled' => ['required_with:gem_timeout_policy', 'boolean'],
            'gem_timeout_policy.timeout_minutes' => ['required_with:gem_timeout_policy', 'integer', 'between:1,1440'],
            'gem_timeout_policy.refund_grace_minutes' => ['required_with:gem_timeout_policy', 'integer', 'between:0,1440'],
            'renewal_interval_seconds' => ['sometimes', 'integer', 'between:10,'.(int) config('trading_v3.max_renewal_interval_seconds', 3600)],
            'gem_bot_ids' => ['sometimes', 'array', 'max:1000'],
            'gem_bot_ids.*' => ['integer', 'min:1', 'distinct'],
            'gold_bot_ids' => ['sometimes', 'array', 'max:1000'],
            'gold_bot_ids.*' => ['integer', 'min:1', 'distinct'],
            'status' => ['prohibited'],
            'server_id' => ['prohibited'],
            'map_id' => ['prohibited'],
            'area_number' => ['prohibited'],
            'coordinates' => ['prohibited'],
            'gem_qty' => ['prohibited'],
            'gold_qty' => ['prohibited'],
            'gold_bar_qty' => ['prohibited'],
        ];
    }
}
