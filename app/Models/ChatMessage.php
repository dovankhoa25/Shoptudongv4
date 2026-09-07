<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMessage extends Model
{
    use SoftDeletes;

    public const SENDER_CUSTOMER = 'customer';

    public const SENDER_AGENT = 'agent';

    public const SENDER_SYSTEM = 'system';

    public const TYPE_TEXT = 'text';

    public const TYPE_SYSTEM = 'system';

    public const TYPE_INTERNAL_NOTE = 'internal_note';

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'sender_kind',
        'type',
        'body',
        'reply_to_id',
        'client_message_id',
        'metadata',
        'is_internal',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_internal' => 'boolean',
            'edited_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id')->withTrashed();
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id')->withTrashed();
    }
}
