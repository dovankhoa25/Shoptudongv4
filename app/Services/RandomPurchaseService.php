<?php

namespace App\Services;

use App\Models\{Category, RandomBox, RandomNick, RandomOrder, User};
use App\Support\ApiCache;
use Illuminate\Support\Facades\{DB, Log};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RandomPurchaseService
{
    public function purchase(int $buyerId, string $slug, int $boxId, ?int $selectedSlot, ?string $key): array
    {
        $fingerprint = hash('sha256', json_encode([$slug, $boxId, $selectedSlot]));
        abort_unless($key, 422, 'Thiếu mã lần mở. Vui lòng tải lại trang.');
        abort_if($selectedSlot !== null && ($selectedSlot < 1 || $selectedSlot > 20), 422, 'Ô mở không hợp lệ.');
        $roll = null;
        $prepared = null;
        $orderId = null;
        try {
            $result = DB::transaction(function () use ($buyerId, $slug, $boxId, $selectedSlot, $key, $fingerprint, &$prepared, &$orderId, &$roll) {
                $prepared = null;
                $orderId = null;
                // Serialize this buyer's attempts, including requests using a stale auth model.
                $buyer = User::whereKey($buyerId)->lockForUpdate()->first();
                abort_unless($buyer && !$buyer->isLocked(), 403, 'Tài khoản không thể mua lúc này.');
                if ($key) {
                    $previous = RandomOrder::where('user_id', $buyerId)->where('purchase_key', $key)->lockForUpdate()->first();
                    if ($previous) {
                        abort_unless(hash_equals($previous->purchase_fingerprint, $fingerprint), 409, 'Mã lần mua đã được dùng cho yêu cầu khác.');
                        return $this->response($previous, $buyerId);
                    }
                }
                $category = Category::where('slug', $slug)->where('is_public', true)->where('status', 'active')->first();
                abort_unless($category, 404, 'Không tìm thấy danh mục.');
                $box = RandomBox::whereKey($boxId)->where('category_id', $category->id)
                    ->where('is_public', true)->sharedLock()->first();
                abort_unless($box, 404, 'Không tìm thấy hộp quà.');
                $price = (int)$box->price;
                abort_if($price < 0, 409, 'Giá hộp quà không hợp lệ.');
                abort_if((int)$buyer->balance < $price, 400, 'Số dư không đủ.');
                $rate = (float)$box->win_rate;
                abort_if($rate < 0 || $rate > 100, 409, 'Tỷ lệ trúng không hợp lệ.');
                // Keep the same draw if the database retries a deadlocked transaction.
                $roll ??= $this->draw();
                $nick = null;
                $reason = 'probability';
                if ($roll <= (int)round($rate * 100)) {
                    $nick = RandomNick::where('random_box_id', $box->id)
                        ->where('status', 'available')
                        ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '<>', $buyerId))
                        ->orderBy('id')->lockForUpdate()->first();
                    $reason = $nick ? null : 'empty_stock';
                }
                $seller = $nick?->user_id ? User::whereKey($nick->user_id)->lockForUpdate()->first() : null;
                abort_if($nick?->user_id && !$seller, 409, 'Không tìm thấy người bán.');
                $order = RandomOrder::create([
                    'user_id' => $buyerId, 'random_box_id' => $box->id, 'random_nick_id' => $nick?->id,
                    'price' => $price, 'result' => $nick ? 'win' : 'lose', 'lose_reason' => $reason,
                    'win_rate_snapshot' => $box->win_rate, 'selected_slot' => $selectedSlot,
                    'purchase_key' => $key, 'purchase_fingerprint' => $fingerprint,
                ]);
                $before = (int)$buyer->balance;
                $buyer->decrement('balance', $price);
                $label = $nick ? "Trúng nick #{$nick->id}" : ($reason === 'empty_stock' ? 'Đã xịt (hết kho)' : 'Đã xịt');
                TransactionService::log(userId: $buyerId, type: 'buy_random', amount: -$price,
                    description: "Mở hộp #{$box->id}, lượt #{$order->id}: {$label}", performedBy: $buyerId,
                    related: $order, relatedId: $order->id, oldBalance: $before, newBalance: $before - $price,
                    idempotencyKey: "random-order:{$order->id}:buyer:{$buyerId}");
                if ($seller) {
                    $before = (int)$seller->balance;
                    $seller->increment('balance', $price);
                    TransactionService::log(userId: $seller->id, type: 'sell_random', amount: $price,
                        description: "Bán random nick #{$nick->id} cho user #{$buyerId}", performedBy: $buyerId,
                        related: $order, relatedId: $order->id, oldBalance: $before, newBalance: $before + $price,
                        idempotencyKey: "random-order:{$order->id}:seller:{$seller->id}");
                }
                $nick?->update(['status' => 'taken']);
                $orderId = $order->id;
                return $prepared = $this->response($order, $buyerId);
            }, 3);
        } catch (Throwable $error) {
            if ($error instanceof HttpExceptionInterface) throw $error;
            // An observer can fail after COMMIT; a confirmed order must not become a failed purchase.
            if (!$prepared || !$orderId || !RandomOrder::whereKey($orderId)->where('user_id', $buyerId)->exists()) throw $error;
            Log::warning('Random purchase committed with a post-commit failure', ['order_id' => $orderId]);
            $result = $prepared;
        }
        try { ApiCache::clearGroups(['public:nick']); }
        catch (Throwable $error) { Log::warning('Random purchase cache invalidation failed', ['order_id' => $result['transaction']['order_id']]); }
        return $result;
    }

    protected function draw(): int
    {
        return random_int(1, 10000);
    }

    private function response(RandomOrder $order, int $buyerId): array
    {
        $nick = $order->random_nick_id ? RandomNick::withTrashed()->find($order->random_nick_id) : null;
        $box = RandomBox::findOrFail($order->random_box_id ?? $nick?->random_box_id);
        $balance = UserBalanceSnapshot::read($buyerId);
        return ['message' => $order->result === 'lose' ? 'Đã xịt' : 'Mở trúng acc',
            'result' => $order->result, 'selected_slot' => $order->selected_slot,
            'win_rate_snapshot' => $order->win_rate_snapshot,
            'nick' => $nick ? ['id' => $nick->id, 'account' => $nick->account, 'password' => $nick->password,
                'description' => $nick->description, 'purchased_at' => $order->created_at] : null,
            'box' => ['id' => $box->id, 'name' => $box->name, 'price' => $order->price],
            'transaction' => ['order_id' => $order->id, 'remaining_balance' => $balance['balance'],
                'balance_revision' => $balance['balance_revision']],
        ];
    }
}
