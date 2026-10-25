<?php
namespace App\Http\Requests\AppAuto\VersionThree;
use Illuminate\Foundation\Http\FormRequest;
class GoldOrderUpdateRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array {
        $rules = [
            'worker_id'=>['sometimes','nullable','uuid'], 'bot_id'=>['required','integer','min:1'],
            'action'=>['required','in:start,claim,phase,receipt,refund_pending,release_unstarted'], 'processing_token'=>['sometimes','nullable','uuid'],
            'character_id'=>['nullable','integer','min:1'],
            'phase'=>['required_if:action,phase','string','in:waiting_customer,waiting_stock,trading,syncing,reconciling,waiting_login'],
            'step_id'=>['required_unless:action,start,claim','uuid'],
            'timeout_seconds'=>['required_if:action,refund_pending','integer','between:1,86400'],
            'bars_qty'=>['required_if:action,receipt','integer','between:0,999'],
            'gold_bar_template_id'=>['sometimes','integer','in:457,2032,281'],
            'pure_gold_qty'=>['required_if:action,receipt','integer','between:0,2147483647'],
            'completed'=>['required_if:action,receipt','boolean'],
            'bot_snapshot'=>['required_if:action,receipt','array:stock_version,gold_qty,gold_bar_qty,nrj_gold_bar_qty,radar_qty,bag_capacity,bag_free_slots,name'],
            'bot_snapshot.stock_version'=>['required_if:action,receipt','integer','min:1'],
            'bot_snapshot.gold_qty'=>['required_if:action,receipt','integer','min:0','max:9000000000000000'],
            'bot_snapshot.gold_bar_qty'=>['required_if:action,receipt','integer','between:0,999'],
            'bot_snapshot.nrj_gold_bar_qty'=>['sometimes','integer','between:0,999'],
            'bot_snapshot.radar_qty'=>['sometimes','integer','between:0,255'],
            'bot_snapshot.bag_capacity'=>['sometimes','integer','between:0,255'],
            'bot_snapshot.bag_free_slots'=>['sometimes','integer','between:0,255'],
            'bot_snapshot.name'=>['nullable','string','max:191'],
            'evidence'=>['required_if:action,receipt,release_unstarted','array:kind,confirmed,intent,fresh_login,no_effect'],
            'evidence.fresh_login'=>['nullable','boolean'],
            'evidence.kind'=>['required_if:action,receipt','in:sale,import'],
            'evidence.confirmed'=>[...($this->input('action')==='phase'?['sometimes']:[]),'exclude_unless:action,receipt,release_unstarted,phase','required_if:action,receipt,release_unstarted','accepted'],
            'evidence.no_effect'=>[...($this->input('action')==='phase'?['sometimes']:[]),'exclude_unless:action,release_unstarted,phase','required_if:action,release_unstarted','accepted'],
            'evidence.intent'=>['nullable','array'],
            'delivery_data'=>['prohibited'],
        ];
        foreach (['map_name','map_id','area_number','coordinates','status','server_id','server_game_id','server_login_id','account_name','account_password','proxy','type'] as $field) $rules['bot_snapshot.'.$field]=['prohibited'];
        // A new claim may carry exact frozen terminal receipts whose HTTP ACK is delayed.
        // Reuse the ordinary receipt validation, including its conditional exclusions,
        // so the saved payload hash is identical through either endpoint shape.
        $receiptRules=$rules;
        $rules['pending_receipts']=['sometimes','prohibited_unless:action,start,claim','array','max:16'];
        $rules['pending_receipts.*']=['required','array:order_id,payload'];
        $rules['pending_receipts.*.order_id']=['required','integer','min:1','distinct'];
        $rules['pending_receipts.*.payload']=['required','array'];
        foreach($receiptRules as $field=>$fieldRules) $rules['pending_receipts.*.payload.'.$field]=array_map(
            fn($rule)=>str_replace(':action,',':pending_receipts.*.payload.action,',$rule),$fieldRules);
        $rules['pending_receipts.*.payload.action']=['required','in:receipt'];
        $rules['pending_receipts.*.payload.completed']=['required','boolean','accepted'];
        $rules['pending_receipts.*.payload.pending_receipts']=['prohibited'];
        $rules['pending_receipts.*.payload.pending_transfers']=['prohibited'];
        $rules['pending_transfers']=['sometimes','prohibited_unless:action,start,claim','array','max:16'];
        $rules['pending_transfers.*']=['required','array:transfer_id,payload'];
        $rules['pending_transfers.*.transfer_id']=['required','uuid','distinct'];
        $rules['pending_transfers.*.payload']=['required','array'];
        foreach((new GoldTransferUpdateRequest)->rules() as $field=>$fieldRules)
            $rules['pending_transfers.*.payload.'.$field]=array_map(fn($rule)=>str_replace(
                [':action,',':from_bot_id'],[':pending_transfers.*.payload.action,',':pending_transfers.*.payload.from_bot_id'],$rule),$fieldRules);
        $rules['pending_transfers.*.payload.action']=['required','in:receipt'];
        $rules['pending_transfers.*.payload.pending_receipts']=['prohibited'];
        $rules['pending_transfers.*.payload.pending_transfers']=['prohibited'];
        return $rules;
    }
}
