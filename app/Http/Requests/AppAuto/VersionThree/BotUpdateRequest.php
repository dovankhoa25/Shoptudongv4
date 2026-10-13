<?php
namespace App\Http\Requests\AppAuto\VersionThree;
use Illuminate\Foundation\Http\FormRequest;
class BotUpdateRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array { return [
        'map_name'=>['prohibited'], 'map_id'=>['prohibited'], 'area_number'=>['prohibited'], 'coordinates'=>['prohibited'],
        'status'=>['prohibited'], 'server_id'=>['prohibited'], 'server_login_id'=>['prohibited'],
        'account_name'=>['prohibited'], 'account_password'=>['prohibited'], 'proxy'=>['prohibited'],
        'worker_id'=>['required','uuid'], 'action'=>['required','in:lease,stock,release'],
        'stock_version'=>['required_if:action,stock','integer','min:1'],
        'gem_qty'=>['required_if:action,stock','integer','min:0'],
        'gift_ticket_qty'=>['required_if:action,stock','integer','min:0'],
        'consign_item_qty'=>['required_if:action,stock','integer','min:0'],
        'character_id'=>['nullable','integer','min:1'], 'name'=>['nullable','string','max:191'],
    ]; }
}

