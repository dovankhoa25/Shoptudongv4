<?php
namespace App\Http\Requests\AppAuto\VersionThree;
use Illuminate\Foundation\Http\FormRequest;
class GoldActivityRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array { return [
        'worker_id'=>['sometimes','nullable','uuid'],
        'code'=>['required',\Illuminate\Validation\Rule::in(array_keys(\App\Services\TradingV3\TradingActivityPublisher::labels('bot')))],
        'order_id'=>['nullable','integer','exists:gold_transactions,id'], 'customer_message'=>['nullable','string','max:220'],
        'map_id'=>['nullable','integer','min:-1'], 'zone'=>['nullable','integer','min:-1'], 'x'=>['nullable','integer'], 'y'=>['nullable','integer'],
        'online_seconds'=>['nullable','integer','min:0'], 'wait_seconds'=>['nullable','integer','min:0'],
    ]; }
}
