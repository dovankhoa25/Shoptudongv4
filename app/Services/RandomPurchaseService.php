<?php

namespace App\Services;

use App\Models\{Category, RandomBox, RandomNick, RandomOrder, User};
use App\Support\ApiCache;
use Illuminate\Support\Facades\{DB, Log};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RandomPurchaseService
{
    public function purchase(int $buyerId, string $slug, int $boxId, ?int $nickId, ?string $key): array
    {
        $fingerprint = hash('sha256', json_encode([$slug, $boxId, $nickId]));
        $prepared = null;
        $orderId = null;
        try {
            $result = DB::transaction(function () use ($buyerId, $slug, $boxId, $nickId, $key, $fingerprint, &$prepared, &$orderId) {
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
                $category = Category::where('slug', $slug)->first();
                abort_unless($category, 404, 'Không tìm thấy danh mục.');
                $box = RandomBox::whereKey($boxId)->where('category_id', $category->id)
                    ->where('is_public', true)->sharedLock()->first();
                abort_unless($box, 404, 'Không tìm thấy hộp quà.');
                $query = RandomNick::where('random_box_id', $box->id)->lockForUpdate();
                $nick = $nickId ? $query->whereKey($nickId)->first() : $query->where('status', 'available')->inRandomOrder()->first();
                abort_unless($nick, 404, 'Hộp quà không còn nick phù hợp.');
                if ($nick->status !== 'available') {
                    $previous = RandomOrder::where('user_id', $buyerId)->where('random_nick_id', $nick->id)->first();
                    if ($nickId && $previous) return $this->response($previous, $buyerId);
                    abort(409, 'Nick đã được mua.');
                }
                abort_if((int)$nick->user_id === $buyerId, 403, 'Không thể tự mua nick của mình.');
                $price = (int)$box->price;
                abort_if($price < 0, 409, 'Giá hộp quà không hợp lệ.');
                abort_if((int)$buyer->balance < $price, 400, 'Số dư không đủ.');
                $seller = $nick->user_id ? User::whereKey($nick->user_id)->lockForUpdate()->first() : null;
                abort_if($nick->user_id && !$seller, 409, 'Không tìm thấy người bán.');
                $before = (int)$buyer->balance;
                $buyer->decrement('balance', $price);
                $type = $nickId ? 'buy_random_specific' : 'buy_random';
                TransactionService::log(userId: $buyerId, type: $type, amount: -$price,
                    description: "Mua random nick #{$nick->id} từ box #{$box->id}", performedBy: $buyerId,
                    related: $nick, relatedId: $nick->id, oldBalance: $before, newBalance: $before - $price,
                    idempotencyKey: "random-purchase:{$nick->id}:buyer:{$buyerId}");
                if ($seller) {
                    $before = (int)$seller->balance;
                    $seller->increment('balance', $price);
                    TransactionService::log(userId: $seller->id, type: 'sell_random', amount: $price,
                        description: "Bán random nick #{$nick->id} cho user #{$buyerId}", performedBy: $buyerId,
                        related: $nick, relatedId: $nick->id, oldBalance: $before, newBalance: $before + $price,
                        idempotencyKey: "random-purchase:{$nick->id}:seller:{$seller->id}");
                }
                $nick->update(['status' => 'taken']);
                $order = RandomOrder::create(['user_id' => $buyerId, 'random_nick_id' => $nick->id,
                    'price' => $price, 'purchase_key' => $key, 'purchase_fingerprint' => $key ? $fingerprint : null]);
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

    private function response(RandomOrder $order, int $buyerId): array
    {
        $nick = RandomNick::withTrashed()->findOrFail($order->random_nick_id);
        $box = RandomBox::findOrFail($nick->random_box_id);
        $balance = UserBalanceSnapshot::read($buyerId);
        return ['message' => 'Mua thành công',
            'nick' => ['id' => $nick->id, 'account' => $nick->account, 'password' => $nick->password,
                'description' => $nick->description, 'purchased_at' => $order->created_at],
            'box' => ['id' => $box->id, 'name' => $box->name, 'price' => $order->price],
            'transaction' => ['order_id' => $order->id, 'remaining_balance' => $balance['balance'],
                'balance_revision' => $balance['balance_revision']],
        ];
    }
}
