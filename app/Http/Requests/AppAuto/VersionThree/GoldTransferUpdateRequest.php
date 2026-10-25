<?php
namespace App\Http\Requests\AppAuto\VersionThree;
use Illuminate\Foundation\Http\FormRequest;
class GoldTransferUpdateRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        $rules = [
            'worker_id'=>['sometimes','nullable','uuid'], 'action'=>['required','in:claim,receipt,cancel'],
            'server_id'=>['sometimes','integer','min:1'],
            'from_bot_id'=>['required','integer','min:1'], 'to_bot_id'=>['required','integer','min:1','different:from_bot_id'],
            'bars_qty'=>['required','integer','between:0,999'], 'pure_gold_qty'=>['sometimes','integer','between:0,2147483647'],
            'gold_bar_template_id'=>['sometimes','integer','in:457,2032,281'],
            'processing_token'=>['sometimes','nullable','uuid'], 'step_id'=>['required_unless:action,claim','uuid'],
            'evidence'=>['required_unless:action,claim','array:kind,confirmed,unchanged,intent,fresh_login'],
            'evidence.fresh_login'=>['nullable','boolean'],
            'evidence.kind'=>['sometimes','in:transfer'],
            'evidence.confirmed'=>['exclude_if:action,claim','required','accepted'],
            'evidence.unchanged'=>['exclude_unless:action,cancel','required','accepted'],
            'evidence.intent'=>['nullable','array'],
        ];
        foreach (['from_snapshot','to_snapshot'] as $prefix) {
            $rules[$prefix]=['required_if:action,receipt','array:stock_version,gold_qty,gold_bar_qty,nrj_gold_bar_qty,radar_qty,bag_capacity,bag_free_slots,name'];
            $rules[$prefix.'.stock_version']=['required_if:action,receipt','integer','min:1'];
            $rules[$prefix.'.gold_qty']=['required_if:action,receipt','integer','min:0','max:9000000000000000'];
            $rules[$prefix.'.gold_bar_qty']=['required_if:action,receipt','integer','between:0,999'];
            $rules[$prefix.'.nrj_gold_bar_qty']=['sometimes','integer','between:0,999'];
            foreach(['radar_qty','bag_capacity','bag_free_slots'] as $field) $rules[$prefix.'.'.$field]=['sometimes','integer','between:0,255'];
            $rules[$prefix.'.name']=['nullable','string','max:191'];
            foreach (['map_name','map_id','area_number','coordinates','status','server_id','server_game_id','server_login_id','account_name','account_password','proxy','type'] as $field) $rules[$prefix.'.'.$field]=['prohibited'];
        }
        return $rules;
    }
}
