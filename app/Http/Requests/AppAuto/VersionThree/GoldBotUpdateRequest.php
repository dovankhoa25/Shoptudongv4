<?php
namespace App\Http\Requests\AppAuto\VersionThree;
use Illuminate\Foundation\Http\FormRequest;
class GoldBotUpdateRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        $rules = [
            'worker_id'=>['required','uuid'], 'action'=>['required','in:lease,stock,release'],
            'stock_version'=>['required_if:action,stock','integer','min:1'],
            'gold_qty'=>['required_if:action,stock','integer','min:0','max:9000000000000000'],
            'gold_bar_qty'=>['required_if:action,stock','integer','between:0,999'],
            'name'=>['nullable','string','max:191'],
        ];
        foreach (['map_name','map_id','area_number','coordinates','status','server_id','server_game_id','server_login_id','account_name','account_password','proxy','type'] as $field) $rules[$field]=['prohibited'];
        return $rules;
    }
}
