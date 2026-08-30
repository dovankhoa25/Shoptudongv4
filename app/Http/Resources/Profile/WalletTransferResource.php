<?php
namespace App\Http\Resources\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class WalletTransferResource extends JsonResource {
    public function toArray(Request $request): array { return [
        'id'=>$this->id, 'transfer_code'=>$this->transfer_code, 'from_wallet_id'=>$this->from_wallet_id,
        'to_wallet_id'=>$this->to_wallet_id, 'amount_from'=>$this->amount_from, 'amount_to'=>$this->amount_to,
        'fee_amount'=>$this->fee_amount, 'from_gold_price'=>$this->from_gold_price,
        'to_gold_price'=>$this->to_gold_price, 'exchange_rate'=>$this->exchange_rate, 'status'=>$this->status,
        'failure_reason'=>$this->failure_reason, 'completed_at'=>$this->completed_at?->toISOString(),
        'cancelled_at'=>$this->cancelled_at?->toISOString(), 'created_at'=>$this->created_at?->toISOString(),
    ]; }
}
