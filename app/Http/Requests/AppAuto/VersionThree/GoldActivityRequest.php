<?php
namespace App\Http\Requests\AppAuto\VersionThree;
use Illuminate\Foundation\Http\FormRequest;
class GoldActivityRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array { return [
        'worker_id'=>['required','uuid'],
        'code'=>['required','in:disabled,paused,connecting,reconnecting,reconciling,login_wait,travelling,ready,warmup,waiting_customer,waiting_stock,trading,selling,importing,transferring,storage_idle,source_empty,syncing,error,name_mismatch,customer_offline,trade_cancelled,api_wait,giving_bars,receiving_bars,storage,refilling,reserve_idle,capacity_full,out_of_bars'],
        'order_id'=>['nullable','integer','exists:gold_transactions,id'], 'customer_message'=>['nullable','string','max:220'],
        'map_id'=>['nullable','integer','min:-1'], 'zone'=>['nullable','integer','min:-1'], 'x'=>['nullable','integer'], 'y'=>['nullable','integer'],
        'online_seconds'=>['nullable','integer','min:0'], 'wait_seconds'=>['nullable','integer','min:0'],
    ]; }
}
