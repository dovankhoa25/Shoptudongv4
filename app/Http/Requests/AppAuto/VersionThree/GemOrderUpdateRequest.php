<?php
namespace App\Http\Requests\AppAuto\VersionThree;
use Illuminate\Foundation\Http\FormRequest;
class GemOrderUpdateRequest extends FormRequest {
    public function authorize(): bool { return true; }
    public function rules(): array { return [
        'bot_snapshot.map_name'=>['prohibited'], 'bot_snapshot.map_id'=>['prohibited'],
        'bot_snapshot.area_number'=>['prohibited'], 'bot_snapshot.coordinates'=>['prohibited'], 'bot_snapshot.status'=>['prohibited'],
        'worker_id'=>['required','uuid'], 'bot_id'=>['required','integer','min:1'],
        'action'=>['required','in:claim,phase,receipt'], 'processing_token'=>['required_unless:action,claim','uuid'],
        'character_id'=>['nullable','integer','min:1'],
        'phase'=>['required_if:action,phase','string','in:waiting_customer,giving_item,waiting_consignment,gifting,buying,waiting_login,review_required,ready'],
        'delivery_data'=>['nullable','array:delivery_item,listing_id,recipient_balance_observed'],
        'delivery_data.claim'=>['prohibited'],
        'delivery_data.delivery_item'=>['nullable','array:template_id,signature,quantity,verified,options'],
        'delivery_data.delivery_item.template_id'=>['required_with:delivery_data.delivery_item','integer','min:0'],
        'delivery_data.delivery_item.signature'=>['required_with:delivery_data.delivery_item','string','max:4096'],
        'delivery_data.delivery_item.quantity'=>['required_with:delivery_data.delivery_item','integer','in:1'],
        'delivery_data.delivery_item.verified'=>['required_with:delivery_data.delivery_item','boolean'],
        'delivery_data.delivery_item.options'=>['nullable','array'],
        'delivery_data.delivery_item.options.*.id'=>['required','integer','min:0'],
        'delivery_data.delivery_item.options.*.param'=>['required','integer'],
        'delivery_data.listing_id'=>['nullable','integer'],
        'delivery_data.recipient_balance_observed'=>['nullable','boolean'],
        'step_id'=>['required_if:action,receipt','uuid'],
        'gem_spent_qty'=>['required_if:action,receipt','integer','min:0'],
        'delivered_gem_qty'=>['required_if:action,receipt','integer','min:0'],
        'tickets_used_qty'=>['required_if:action,receipt','integer','min:0'],
        'completed'=>['required_if:action,receipt','boolean'],
        'bot_snapshot'=>['required_if:action,receipt','array'],
        'bot_snapshot.stock_version'=>['required_if:action,receipt','integer','min:1'],
        'bot_snapshot.gem_qty'=>['required_if:action,receipt','integer','min:0'],
        'bot_snapshot.gift_ticket_qty'=>['required_if:action,receipt','integer','min:0'],
        'bot_snapshot.consign_item_qty'=>['required_if:action,receipt','integer','min:0'],
        'evidence'=>['required_if:action,receipt','array'],
        'evidence.kind'=>['required_if:action,receipt','in:gift,trade,consignment'],
        'evidence.intent'=>['nullable','array'],
        'evidence.confirmed'=>['exclude_unless:action,receipt','required','accepted'],
    ]; }
}

