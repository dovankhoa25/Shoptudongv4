<?php
namespace App\Http\Requests\AppAuto\VersionThree;
use Illuminate\Foundation\Http\FormRequest;
class ActivityRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array { return [
        'worker_id'=>['required','uuid'],
        'code'=>['required','in:disabled,paused,connecting,reconnecting,reconciling,login_wait,travelling,ready,warmup,waiting_customer,giving_item,waiting_consignment,buying,gifting,syncing,review_required,error,out_of_items,insufficient_gems,out_of_tickets,name_mismatch,customer_offline,trade_cancelled,api_wait'],
        'order_id'=>['nullable','integer','exists:gem_transactions,id'], 'customer_message'=>['nullable','string','max:220'],
        'map_id'=>['nullable','integer','min:-1'], 'zone'=>['nullable','integer','min:-1'],
        'x'=>['nullable','integer'], 'y'=>['nullable','integer'],
        'online_seconds'=>['nullable','integer','min:0'], 'wait_seconds'=>['nullable','integer','min:0'],
    ]; }
}

