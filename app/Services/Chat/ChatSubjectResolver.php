<?php

namespace App\Services\Chat;

use App\Models\GemTransaction;
use App\Models\GoldTransaction;
use App\Models\NickOrder;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ChatSubjectResolver
{
    /** @var list<string> */
    public const TYPES = [
        'service_order',
        'nick_order',
        'gold_transaction',
        'gem_transaction',
    ];

    public function resolveOwned(string $type, int $id, User $customer, bool $lockForUpdate = false): Model
    {
        $query = match ($type) {
            'service_order' => ServiceOrder::withoutReceiverOwnedScope(),
            'nick_order' => NickOrder::query(),
            'gold_transaction' => GoldTransaction::query()->where('type', GoldTransaction::TYPE_ORDER),
            'gem_transaction' => GemTransaction::query(),
            default => null,
        };

        if ($query && $lockForUpdate) {
            $query->lockForUpdate();
        }

        $subject = $query?->find($id);

        if (! $subject || $this->customerId($type, $subject) !== (int) $customer->getKey()) {
            throw ValidationException::withMessages([
                'subject_id' => 'Không tìm thấy đơn hàng này trong tài khoản của bạn.',
            ]);
        }

        return $subject;
    }

    public function customerId(string $type, Model $subject): int
    {
        return match ($type) {
            'nick_order' => (int) $subject->getAttribute('buyer_id'),
            default => (int) $subject->getAttribute('user_id'),
        };
    }

    public function suggestedAssigneeId(string $type, Model $subject): ?int
    {
        $id = match ($type) {
            'service_order' => $subject->getAttribute('receiver_id'),
            'nick_order' => $subject->getAttribute('seller_id'),
            default => null,
        };

        return $id === null ? null : (int) $id;
    }

    /** @return array<string, mixed> */
    public function summary(?string $type, ?Model $subject, ?int $fallbackId = null): ?array
    {
        if (! $type || ! $subject) {
            return null;
        }

        $id = (int) ($subject->getKey() ?? $fallbackId);

        return match ($type) {
            'service_order' => [
                'type' => $type,
                'id' => $id,
                'label' => 'Đơn dịch vụ #'.$id,
                'description' => $subject->service?->name ?? 'Dịch vụ game',
                'status' => $subject->getAttribute('status'),
            ],
            'nick_order' => [
                'type' => $type,
                'id' => $id,
                'label' => 'Đơn mua acc #'.$id,
                'description' => $subject->nick?->category?->name ?? 'Tài khoản game',
                'status' => $subject->getAttribute('status'),
            ],
            'gold_transaction' => [
                'type' => $type,
                'id' => $id,
                'label' => 'Đơn vàng #'.$id,
                'description' => trim(($subject->server?->name_view ?? $subject->server?->name ?? '').' · '.($subject->character_name ?? ''), ' ·'),
                'status' => $subject->getAttribute('status'),
            ],
            'gem_transaction' => [
                'type' => $type,
                'id' => $id,
                'label' => 'Đơn ngọc #'.$id,
                'description' => trim(($subject->server?->name_view ?? $subject->server?->name ?? '').' · '.($subject->character_name ?? ''), ' ·'),
                'status' => $subject->getAttribute('status'),
            ],
            default => null,
        };
    }

    /** @return Collection<int, array<string, mixed>> */
    public function listFor(User $customer): Collection
    {
        $serviceOrders = ServiceOrder::withoutReceiverOwnedScope()
            ->where('user_id', $customer->getKey())
            ->with('service:id,name')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (ServiceOrder $order) => $this->contextItem('service_order', $order));

        $nickOrders = NickOrder::query()
            ->where('buyer_id', $customer->getKey())
            ->with('nick.category:id,name')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (NickOrder $order) => $this->contextItem('nick_order', $order));

        $goldOrders = GoldTransaction::query()
            ->where('user_id', $customer->getKey())
            ->where('type', GoldTransaction::TYPE_ORDER)
            ->with('server:id,name,name_view')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (GoldTransaction $order) => $this->contextItem('gold_transaction', $order));

        $gemOrders = GemTransaction::query()
            ->where('user_id', $customer->getKey())
            ->with('server:id,name,name_view')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(fn (GemTransaction $order) => $this->contextItem('gem_transaction', $order));

        return collect()
            ->concat($serviceOrders)
            ->concat($nickOrders)
            ->concat($goldOrders)
            ->concat($gemOrders)
            ->sortByDesc('created_at')
            ->take(50)
            ->values();
    }

    /** @return array<string, mixed> */
    private function contextItem(string $type, Model $subject): array
    {
        return [
            ...($this->summary($type, $subject) ?? []),
            'created_at' => $subject->getAttribute('created_at')?->toIso8601String(),
        ];
    }
}
