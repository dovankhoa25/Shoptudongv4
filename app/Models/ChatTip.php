<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatTip extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'uuid',
        'conversation_id',
        'payer_id',
        'recipient_id',
        'message_id',
        'payer_transaction_id',
        'recipient_transaction_id',
        'amount',
        'platform_fee',
        'recipient_amount',
        'currency',
        'status',
        'idempotency_key',
        'note',
        'completed_at',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'platform_fee' => 'integer',
            'recipient_amount' => 'integer',
            'completed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id')->withTrashed();
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id')->withTrashed();
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id')->withTrashed();
    }

    public function payerTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'payer_transaction_id');
    }

    public function recipientTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'recipient_transaction_id');
    }
}
