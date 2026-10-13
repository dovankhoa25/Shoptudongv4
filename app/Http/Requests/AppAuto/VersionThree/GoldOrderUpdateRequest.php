<?php
namespace App\Http\Requests\AppAuto\VersionThree;
use Illuminate\Foundation\Http\FormRequest;
class GoldOrderUpdateRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        $rules = [
            'worker_id'=>['required','uuid'], 'bot_id'=>['required','integer','min:1'],
            'action'=>['required','in:claim,phase,receipt'], 'processing_token'=>['required_unless:action,claim','uuid'],
            'character_id'=>['nullable','integer','min:1'],
            'phase'=>['required_if:action,phase','string','in:waiting_customer,waiting_stock,trading,syncing,reconciling,waiting_login'],
            'step_id'=>['required_unless:action,claim','uuid'],
            'bars_qty'=>['required_if:action,receipt','integer','between:0,999'],
            'pure_gold_qty'=>['required_if:action,receipt','integer','between:0,2147483647'],
            'completed'=>['required_if:action,receipt','boolean'],
            'bot_snapshot'=>['required_if:action,receipt','array:stock_version,gold_qty,gold_bar_qty,name'],
            'bot_snapshot.stock_version'=>['required_if:action,receipt','integer','min:1'],
            'bot_snapshot.gold_qty'=>['required_if:action,receipt','integer','min:0','max:9000000000000000'],
            'bot_snapshot.gold_bar_qty'=>['required_if:action,receipt','integer','between:0,999'],
            'bot_snapshot.name'=>['nullable','string','max:191'],
            'evidence'=>['required_if:action,receipt','array:kind,confirmed,intent,fresh_login'],
            'evidence.fresh_login'=>['nullable','boolean'],
            'evidence.kind'=>['required_if:action,receipt','in:sale,import'],
            'evidence.confirmed'=>['exclude_unless:action,receipt','required','accepted'],
            'evidence.intent'=>['nullable','array'],
            'delivery_data'=>['prohibited'],
        ];
        foreach (['map_name','map_id','area_number','coordinates','status','server_id','server_game_id','server_login_id','account_name','account_password','proxy','type'] as $field) $rules['bot_snapshot.'.$field]=['prohibited'];
        return $rules;
    }
}
