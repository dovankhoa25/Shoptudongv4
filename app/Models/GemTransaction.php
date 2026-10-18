<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GemTransaction extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';

    const STATUS_PROCESSING = 'processing';

    const STATUS_COMPLETED = 'completed';

    const STATUS_CANCELLED = 'cancelled';

    const STATUS_REFUNDED = 'refunded';

    protected $fillable = [
        'bot_id', 'delivery_method', 'character_id', 'delivery_phase', 'delivery_data', 'processing_started_at', 'completed_at', 'delivered_gem_qty', 'bot_gem_spent_qty', 'tickets_used_qty',
        'user_id',
        'server_id',
        'character_name',
        'item',
        'amount_vnd',
        'gem_qty',
        'price_at_transaction',
        'status',
        'updated_by',
        'last_synced_at',
        'cancel_requested_at',
        'refunded_at',
    ];

    protected $casts = [
        'character_id' => 'integer', 'bot_gem_spent_qty' => 'integer', 'delivered_gem_qty' => 'integer', 'tickets_used_qty' => 'integer',
        'delivery_data' => 'array', 'processing_started_at' => 'datetime', 'completed_at' => 'datetime',
        'amount_vnd' => 'decimal:0',
        'gem_qty' => 'integer',
        'price_at_transaction' => 'decimal:0',
        'last_synced_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    protected $hidden = [
        'bot_id', 'delivery_method', 'character_id', 'delivery_phase', 'delivery_data', 'processing_started_at', 'completed_at', 'delivered_gem_qty', 'bot_gem_spent_qty', 'tickets_used_qty',
        'cancel_requested_at',
        'refunded_at',
    ];

    public function hasDeliveryCheckpoint(): bool {return !empty($this->delivery_data['claim']);}
    public function scopeWithoutDeliveryCheckpoint($query) {return $query->whereNull('delivery_data->claim');}

    /** Ticket rates keep their precision; legacy consignment responses keep their old format. */
    public function getPriceAtTransactionAttribute($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->asDecimal($value, ($this->attributes['delivery_method'] ?? 'consignment') === 'gift_ticket' ? 2 : 0);
    }

    /**
     * Get the user that owns the transaction
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the server for the transaction
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Scope for pending transactions
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope for processing transactions
     */
    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * Scope for completed transactions
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope for cancelled transactions
     */
    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeRefunded($query)
    {
        return $query->where('status', self::STATUS_REFUNDED);
    }

    /**
     * Calculate gem quantity from VND amount
     */
    public function calculateGemQuantity()
    {
        if ($this->amount_vnd && $this->price_at_transaction) {
            $this->gem_qty = floor($this->amount_vnd / $this->price_at_transaction);

            return $this->gem_qty;
        }

        return 0;
    }

    /**
     * Calculate VND amount from gem quantity
     */
    public function calculateVndAmount()
    {
        if ($this->gem_qty && $this->price_at_transaction) {
            $this->amount_vnd = $this->gem_qty * $this->price_at_transaction;

            return $this->amount_vnd;
        }

        return 0;
    }

    /**
     * Update transaction status
     */
    public function updateStatus($status, $updatedBy = 'web')
    {
        $this->update([
            'status' => $status,
            'updated_by' => $updatedBy,
            'last_synced_at' => now(),
        ]);

        return $this;
    }

    /**
     * Check if transaction can be processed
     */
    public function canProcess()
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Process transaction
     */
    public function process()
    {
        if (! $this->canProcess()) {
            return false;
        }

        $this->updateStatus(self::STATUS_PROCESSING);

        // Logic xử lý giao dịch với bot
        $bot = GemBot::getBotWithMostGems($this->server_id);

        if ($bot && $bot->gem_qty >= $this->gem_qty) {
            // Trừ ngọc từ bot
            $bot->updateGemQuantity($this->gem_qty, 'subtract');

            // Hoàn thành giao dịch
            $this->updateStatus(self::STATUS_COMPLETED);

            return true;
        }

        // Nếu không đủ ngọc, đưa về pending
        $this->updateStatus(self::STATUS_PENDING);

        return false;
    }

    /**
     * Cancel transaction
     */
    public function cancel()
    {
        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED])) {
            return false;
        }

        $this->updateStatus(self::STATUS_CANCELLED);

        // Logic hoàn tiền nếu cần
        // ...

        return true;
    }

    public function historySnapshot(): array
    {
        return $this->only([
            'user_id',
            'server_id',
            'character_name',
            'item',
            'amount_vnd',
            'gem_qty',
            'price_at_transaction',
            'status',
            'updated_by',
            'last_synced_at',
            'cancel_requested_at',
            'refunded_at',
        ]);
    }
}
