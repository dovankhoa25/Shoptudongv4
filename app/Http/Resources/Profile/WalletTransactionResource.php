<?php
namespace App\Http\Resources\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
class WalletTransactionResource extends JsonResource {
    public function toArray(Request $request): array { return [
        'id'=>$this->id, 'wallet_id'=>$this->wallet_id, 'type'=>$this->type, 'amount'=>$this->amount,
        'balance_before'=>$this->balance_before, 'balance_after'=>$this->balance_after,
        'locked_before'=>$this->locked_before, 'locked_after'=>$this->locked_after,
        'reference_type'=>$this->reference_type, 'reference_id'=>$this->reference_id,
        'description'=>$this->description, 'metadata'=>$this->metadata, 'created_at'=>$this->created_at?->toISOString(),
    ]; }
}
