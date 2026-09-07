<?php

namespace App\Services\Chat;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatTip;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChatTipService
{
    /**
     * @return array{
     *     enabled: bool,
     *     min_amount: int,
     *     max_amount: int,
     *     daily_limit: int,
     *     remaining_daily_limit: int,
     *     balance: int,
     *     recipients: list<array{id: int, username: string, display_name: string, avatar: string|null}>
     * }
     */
    public function options(ChatConversation $conversation, User $viewer): array
    {
        $minimum = $this->minimumAmount();
        $maximum = $this->maximumAmount();
        $dailyLimit = $this->dailyLimit();
        $isCustomer = (int) $conversation->customer_id === (int) $viewer->getKey();
        $balance = $isCustomer
            ? (int) (User::query()->whereKey($viewer->getKey())->value('balance') ?? 0)
            : (int) $viewer->balance;
        $recipients = $isCustomer ? $this->eligibleRecipients($conversation, $viewer) : collect();
        $spentToday = $isCustomer
            ? (int) ChatTip::query()
                ->where('payer_id', $viewer->getKey())
                ->where('status', ChatTip::STATUS_COMPLETED)
                ->where('created_at', '>=', now()->startOfDay())
                ->sum('amount')
            : 0;
        $remainingDaily = max(0, $dailyLimit - $spentToday);

        return [
            'enabled' => $isCustomer
                && ! $viewer->isLocked()
                && $conversation->status !== ChatConversation::STATUS_CLOSED
                && $recipients->isNotEmpty()
                && $balance >= $minimum
                && $remainingDaily >= $minimum,
            'min_amount' => $minimum,
            'max_amount' => $maximum,
            'daily_limit' => $dailyLimit,
            'remaining_daily_limit' => $remainingDaily,
            'balance' => $balance,
            'recipients' => $recipients
                ->map(fn (User $recipient): array => [
                    'id' => (int) $recipient->getKey(),
                    'username' => $recipient->username,
                    'display_name' => $recipient->chatDisplayName(),
                    'avatar' => $recipient->chat_avatar_url,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array{recipient_id: int, amount: int, idempotency_key: string, note?: string|null}  $attributes
     * @return array{
     *     tip: ChatTip,
     *     message: ChatMessage,
     *     conversation: ChatConversation,
     *     created: bool,
     *     payer_balance: int,
     *     recipient_balance: int
     * }
     */
    public function create(ChatConversation $conversation, User $payer, array $attributes): array
    {
        $recipientId = (int) $attributes['recipient_id'];
        $amount = (int) $attributes['amount'];
        $idempotencyKey = trim($attributes['idempotency_key']);
        $note = trim((string) ($attributes['note'] ?? '')) ?: null;

        return DB::transaction(function () use (
            $conversation,
            $payer,
            $recipientId,
            $amount,
            $idempotencyKey,
            $note,
        ): array {
            $lockedConversation = ChatConversation::query()
                ->lockForUpdate()
                ->findOrFail($conversation->getKey());

            if ((int) $lockedConversation->customer_id !== (int) $payer->getKey()) {
                throw new AuthorizationException('Chỉ khách hàng của cuộc trò chuyện mới có thể gửi ủng hộ.');
            }

            /** @var Collection<int, User> $lockedUsers */
            $lockedUsers = User::withTrashed()
                ->whereIn('id', [(int) $payer->getKey(), $recipientId])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (User $user): int => (int) $user->getKey());
            $lockedPayer = $lockedUsers->get((int) $payer->getKey());
            $recipient = $lockedUsers->get($recipientId);

            if (! $lockedPayer || $lockedPayer->trashed() || $lockedPayer->isLocked()) {
                throw new AuthorizationException('Tài khoản không thể thực hiện giao dịch này.');
            }

            $existing = ChatTip::query()
                ->where('payer_id', $lockedPayer->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->ensureSameRequest($existing, $lockedConversation, $recipientId, $amount, $note);

                if ($existing->status !== ChatTip::STATUS_COMPLETED || ! $existing->message_id) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Khoản ủng hộ này chưa hoàn tất, vui lòng thử lại sau.',
                    ]);
                }

                return $this->result(
                    $existing,
                    $lockedConversation,
                    false,
                    (int) $lockedPayer->balance,
                    (int) ($recipient?->balance ?? 0),
                );
            }

            if ($recipientId === (int) $lockedPayer->getKey()) {
                throw ValidationException::withMessages([
                    'recipient_id' => 'Bạn không thể tự ủng hộ chính mình.',
                ]);
            }

            if ($lockedConversation->status === ChatConversation::STATUS_CLOSED) {
                throw ValidationException::withMessages([
                    'conversation' => 'Không thể gửi ủng hộ trong cuộc trò chuyện đã đóng.',
                ]);
            }

            if ($amount < $this->minimumAmount() || $amount > $this->maximumAmount()) {
                throw ValidationException::withMessages([
                    'amount' => 'Số tiền ủng hộ không nằm trong giới hạn cho phép.',
                ]);
            }

            if (! $recipient
                || $recipient->trashed()
                || $recipient->status !== User::STATUS_ACTIVE
                || ! $this->canReceiveTip($recipient)
                || ! $this->eligibleRecipientIds($lockedConversation)->contains($recipientId)) {
                throw ValidationException::withMessages([
                    'recipient_id' => 'Người nhận không thuộc đội ngũ hỗ trợ của cuộc trò chuyện này.',
                ]);
            }

            $spentToday = (int) ChatTip::query()
                ->where('payer_id', $lockedPayer->getKey())
                ->where('status', ChatTip::STATUS_COMPLETED)
                ->where('created_at', '>=', now()->startOfDay())
                ->sum('amount');
            if ($spentToday > $this->dailyLimit() - $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'Khoản ủng hộ vượt giới hạn trong ngày.',
                ]);
            }

            $payerBalanceBefore = (int) $lockedPayer->balance;
            if ($payerBalanceBefore < $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'Số dư hiện tại không đủ để gửi khoản ủng hộ này.',
                ]);
            }

            // Donations currently go entirely to the selected supporter. Keep
            // the persisted fee field at zero until a platform ledger exists.
            $platformFee = 0;
            $recipientAmount = $amount - $platformFee;
            $recipientBalanceBefore = (int) $recipient->balance;
            if ($recipientAmount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Số tiền người nhận được phải lớn hơn 0.',
                ]);
            }
            if ($recipientBalanceBefore > TransactionService::MAX_BALANCE - $recipientAmount) {
                throw ValidationException::withMessages([
                    'amount' => 'Số dư người nhận sẽ vượt giới hạn lưu trữ.',
                ]);
            }

            $tip = ChatTip::query()->create([
                'uuid' => (string) Str::uuid(),
                'conversation_id' => $lockedConversation->getKey(),
                'payer_id' => $lockedPayer->getKey(),
                'recipient_id' => $recipient->getKey(),
                'amount' => $amount,
                'platform_fee' => $platformFee,
                'recipient_amount' => $recipientAmount,
                'currency' => 'VND',
                'status' => ChatTip::STATUS_PENDING,
                'idempotency_key' => $idempotencyKey,
                'note' => $note,
            ]);

            $payerBalanceAfter = $payerBalanceBefore - $amount;
            $recipientBalanceAfter = $recipientBalanceBefore + $recipientAmount;
            $lockedPayer->forceFill(['balance' => $payerBalanceAfter])->save();
            $recipient->forceFill(['balance' => $recipientBalanceAfter])->save();

            $payerTransaction = TransactionService::log(
                userId: (int) $lockedPayer->getKey(),
                type: Transaction::TYPE_CHAT_TIP_SENT,
                amount: -$amount,
                description: 'Ủng hộ '.$recipient->chatDisplayName().' trong cuộc trò chuyện #'.$lockedConversation->getKey(),
                performedBy: (int) $lockedPayer->getKey(),
                related: $tip,
                oldBalance: $payerBalanceBefore,
                newBalance: $payerBalanceAfter,
                idempotencyKey: "chat-tip:{$lockedPayer->getKey()}:{$idempotencyKey}:sent",
                metadata: [
                    'conversation_id' => (int) $lockedConversation->getKey(),
                    'recipient_id' => (int) $recipient->getKey(),
                    'platform_fee' => $platformFee,
                ],
            );
            $recipientTransaction = TransactionService::log(
                userId: (int) $recipient->getKey(),
                type: Transaction::TYPE_CHAT_TIP_RECEIVED,
                amount: $recipientAmount,
                description: 'Nhận ủng hộ từ '.$lockedPayer->username.' trong cuộc trò chuyện #'.$lockedConversation->getKey(),
                performedBy: (int) $lockedPayer->getKey(),
                related: $tip,
                oldBalance: $recipientBalanceBefore,
                newBalance: $recipientBalanceAfter,
                idempotencyKey: "chat-tip:{$lockedPayer->getKey()}:{$idempotencyKey}:received",
                metadata: [
                    'conversation_id' => (int) $lockedConversation->getKey(),
                    'payer_id' => (int) $lockedPayer->getKey(),
                    'gross_amount' => $amount,
                    'platform_fee' => $platformFee,
                ],
            );

            $message = $lockedConversation->messages()->create([
                'sender_id' => $lockedPayer->getKey(),
                'sender_kind' => ChatMessage::SENDER_CUSTOMER,
                'type' => ChatMessage::TYPE_TIP,
                'body' => $lockedPayer->username.' đã ủng hộ '.$recipient->chatDisplayName().' '
                    .number_format($amount, 0, ',', '.').' đ 🎉',
                'metadata' => [
                    'tip_id' => (int) $tip->getKey(),
                    'tip_uuid' => $tip->uuid,
                    'recipient_id' => (int) $recipient->getKey(),
                    'amount' => $amount,
                    'recipient_amount' => $recipientAmount,
                    'currency' => 'VND',
                    'status' => ChatTip::STATUS_COMPLETED,
                ],
                'is_internal' => false,
            ]);

            $tip->forceFill([
                'message_id' => $message->getKey(),
                'payer_transaction_id' => $payerTransaction->getKey(),
                'recipient_transaction_id' => $recipientTransaction->getKey(),
                'status' => ChatTip::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();

            $participant = ChatParticipant::query()->updateOrCreate(
                ['conversation_id' => $lockedConversation->getKey(), 'user_id' => $lockedPayer->getKey()],
                ['role' => ChatParticipant::ROLE_CUSTOMER, 'joined_at' => now(), 'left_at' => null],
            );
            $participant->forceFill([
                'last_read_message_id' => $message->getKey(),
                'last_read_at' => now(),
            ])->save();

            // A tip is a financial event, not a new support question: keep the
            // workflow status while still moving the conversation to the top.
            $lockedConversation->forceFill([
                'last_message_id' => $message->getKey(),
                'last_message_at' => $message->created_at,
            ])->save();

            return $this->result(
                $tip,
                $lockedConversation,
                true,
                $payerBalanceAfter,
                $recipientBalanceAfter,
            );
        }, 3);
    }

    /** @return Collection<int, User> */
    public function eligibleRecipients(ChatConversation $conversation, User $payer): Collection
    {
        $assigneeId = $conversation->assigned_to_id ? (int) $conversation->assigned_to_id : null;

        return User::query()
            ->whereIn('id', $this->eligibleRecipientIds($conversation))
            ->whereKeyNot($payer->getKey())
            ->where('status', User::STATUS_ACTIVE)
            ->with(['roles', 'permissions'])
            ->get()
            ->filter(fn (User $user): bool => $this->canReceiveTip($user))
            ->sortBy(fn (User $user): string => sprintf(
                '%d:%s:%020d',
                (int) $user->getKey() === $assigneeId ? 0 : 1,
                mb_strtolower($user->chatDisplayName()),
                (int) $user->getKey(),
            ))
            ->values();
    }

    /** @return Collection<int, int> */
    private function eligibleRecipientIds(ChatConversation $conversation): Collection
    {
        return collect([$conversation->assigned_to_id])
            ->merge($conversation->messages()
                ->where('sender_kind', ChatMessage::SENDER_AGENT)
                ->where('is_internal', false)
                ->whereNotNull('sender_id')
                ->distinct()
                ->pluck('sender_id'))
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    private function ensureSameRequest(
        ChatTip $tip,
        ChatConversation $conversation,
        int $recipientId,
        int $amount,
        ?string $note,
    ): void {
        if ((int) $tip->conversation_id !== (int) $conversation->getKey()
            || (int) $tip->recipient_id !== $recipientId
            || (int) $tip->amount !== $amount
            || $tip->note !== $note) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Mã giao dịch này đã được dùng cho một yêu cầu khác.',
            ]);
        }
    }

    private function canReceiveTip(User $user): bool
    {
        return $user->status === User::STATUS_ACTIVE
            && ($user->hasAnyRole(['super-admin', 'admin', 'ctv'])
                || $user->hasPermissionTo('chats.view', 'web')
                || $user->hasPermissionTo('chats.reply', 'web'))
            && $user->hasPermissionTo('chats.reply', 'web');
    }

    /**
     * @return array{
     *     tip: ChatTip,
     *     message: ChatMessage,
     *     conversation: ChatConversation,
     *     created: bool,
     *     payer_balance: int,
     *     recipient_balance: int
     * }
     */
    private function result(
        ChatTip $tip,
        ChatConversation $conversation,
        bool $created,
        int $payerBalance,
        int $recipientBalance,
    ): array {
        $tip->load([
            'payer:id,username,chat_display_name,avatar',
            'recipient:id,username,chat_display_name,avatar',
        ]);
        $message = ChatMessage::withTrashed()->findOrFail($tip->message_id);

        return [
            'tip' => $tip,
            'message' => $message,
            'conversation' => $conversation->fresh(),
            'created' => $created,
            'payer_balance' => $payerBalance,
            'recipient_balance' => $recipientBalance,
        ];
    }

    private function minimumAmount(): int
    {
        return max(1, (int) config('chat.tips.min_amount', 1_000));
    }

    private function maximumAmount(): int
    {
        return max($this->minimumAmount(), (int) config('chat.tips.max_amount', 1_000_000));
    }

    private function dailyLimit(): int
    {
        return max($this->maximumAmount(), (int) config('chat.tips.daily_limit', 2_000_000));
    }
}
