<?php
namespace App\Http\Resources\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class LuckyNumberBetResource extends JsonResource {
    public function toArray(Request $request): array { return [
        'id'=>$this->id, 'bet_code'=>$this->bet_code, 'wallet_id'=>$this->wallet_id,
        'round_id'=>$this->round_id, 'market_id'=>$this->market_id, 'option_id'=>$this->option_id,
        'selection_value'=>$this->selection_value, 'amount'=>$this->amount,
        'payout_multiplier'=>$this->payout_multiplier, 'potential_payout'=>$this->potential_payout,
        'actual_payout'=>$this->actual_payout, 'status'=>$this->status,
        'placed_at'=>$this->placed_at?->toISOString(), 'settled_at'=>$this->settled_at?->toISOString(),
    ]; }
}
