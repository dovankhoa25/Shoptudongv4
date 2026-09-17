<?php

namespace App\Http\Controllers\Api;

use App\Helpers\AccountEncrypt;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ApiAttributeResource;
use App\Http\Resources\Api\NickResource;
use App\Models\Category;
use App\Models\Nick;
use App\Models\NickOrder;
use App\Models\RandomBox;
use App\Models\RandomNick;
use App\Models\RandomOrder;
use App\Models\Spin;
use App\Models\Transaction;
use App\Models\User;
use App\Scopes\UserOwnedScope;
use App\Services\TransactionService;
use App\Support\ApiCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NickController extends Controller
{
    public function getByCategory(Request $request, $slug)
    {
        $category = Category::query()
            ->where('slug', $slug)
            ->first();

        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $template = $category->template ?? 'default';

        switch ($template) {
            case 'default':
                return $this->handleDefault($request, $category);

            case 'spin':
                return $this->handleSpin($request, $category);

            case 'random':
                return $this->handleRandom($request, $category);

            default:
                return response()->json(['message' => 'Unknown template'], 400);
        }
    }

    private function parseSort(Request $request): array
    {
        $sortParam = $request->query('sort', 'price_desc');

        return match ($sortParam) {
            'id_asc' => [['id', 'asc']],
            'id_desc' => [['id', 'desc']],
            'price_asc' => [['price', 'asc'],  ['id', 'asc']],
            'price_desc' => [['price', 'desc'], ['id', 'asc']],
            default => [['id', 'asc']], // ✅ mặc định: giá cao -> id cũ
            // default      => [['price', 'desc'], ['id', 'asc']], // ✅ mặc định: giá cao -> id cũ
        };
    }

    private function handleDefault(Request $request, Category $category)
    {
        $queryParams = $request->query();
        ksort($queryParams);

        return ApiCache::rememberJson(
            'public:nick',
            ApiCache::key(
                'nick-category',
                $category->id,
                'default',
                json_encode($queryParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
            90,
            function () use ($request, $category) {
                $category->loadMissing('attributes.options');
                $orderBys = $this->parseSort($request);

                $query = Nick::query()
                    ->with('snapshot:id,summary_json')
                    ->select([
                        'snapshot_id',
                        'id',
                        'price',
                        'description',
                        'image',
                        'listing_type',
                        'attribute_cache_json',
                    ])
                    ->where('category_id', $category->id)
                    ->where('status', 'not_sold');

                // Search nick_id (group để không phá các điều kiện filter khác)
                if ($request->filled('nick_id')) {
                    $nickId = trim((string) $request->query('nick_id')); // GET -> query()

                    $query->where(function ($q) use ($nickId) {
                        if (is_numeric($nickId)) {
                            $q->where('id', (int) $nickId);
                        } else {
                            // id thường là int -> search description là chính
                            $q->where('description', 'LIKE', '%'.$nickId.'%');
                        }
                    });
                }

                // Attribute filters: attr_{attributeId} = {optionId}
                foreach ($request->query() as $key => $value) {
                    if (! str_starts_with($key, 'attr_')) {
                        continue;
                    }

                    $attributeID = (int) str_replace('attr_', '', $key);
                    $optionID = (int) $value;

                    // tránh case optionID = 0 / attributeID = 0
                    if ($attributeID <= 0 || $optionID <= 0) {
                        continue;
                    }

                    $query->whereHas('attributes', function ($q) use ($attributeID, $optionID) {
                        $q->where('attributes.id', $attributeID)
                            ->where('attribute_option_id', $optionID);
                    });
                }

                // Price range: "min-max" hoặc "min+"
                if ($request->filled('price_range')) {
                    $range = trim((string) $request->query('price_range'));

                    if (str_contains($range, '-')) {
                        [$min, $max] = array_map('trim', explode('-', $range, 2));
                        $min = (int) $min;
                        $max = (int) $max;

                        if ($min >= 0 && $max > 0 && $min <= $max) {
                            $query->whereBetween('price', [$min, $max]);
                        }
                    } elseif (str_ends_with($range, '+')) {
                        $min = (int) rtrim($range, '+');
                        if ($min > 0) {
                            $query->where('price', '>=', $min);
                        }
                    }
                }

                // Apply multi-order
                foreach ($orderBys as [$field, $dir]) {
                    $query->orderBy($field, $dir);
                }

                $nicks = $query->paginate(20);

                return NickResource::collection($nicks)->additional([
                    'filters' => [
                        'attributes' => ApiAttributeResource::collection($category->attributes),
                    ],
                    'template' => 'default',
                ]);
            }
        );
    }

    private function handleSpin(Request $request, Category $category)
    {
        $queryParams = $request->query();
        ksort($queryParams);

        return ApiCache::rememberJson(
            'public:nick',
            ApiCache::key(
                'nick-category',
                $category->id,
                'spin',
                json_encode($queryParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
            90,
            function () use ($request, $category) {
                $orderBys = $this->parseSort($request);

                $query = Spin::where('category_id', $category->id);

                foreach ($orderBys as [$field, $direction]) {
                    $query->orderBy($field, $direction);
                }

                $spins = $query->paginate(20);

                return [
                    'template' => 'spin',
                    'data' => $spins,
                    'is_spin' => true,
                ];
            }
        );
    }

    private function handleRandom(Request $request, Category $category)
    {
        $queryParams = $request->query();
        ksort($queryParams);

        return ApiCache::rememberJson(
            'public:nick',
            ApiCache::key(
                'nick-category',
                $category->id,
                'random',
                json_encode($queryParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
            90,
            function () use ($request, $category) {
                $orders = $this->parseSort($request);

                $query = RandomBox::where('category_id', $category->id)
                    ->where('is_public', true)
                    ->withCount(['randomNicks as available_nicks_count' => function ($query) {
                        $query->where('status', 'available');
                    }])
                    ->orderBy('sort_order', 'asc');

                foreach ($orders as [$field, $dir]) {
                    $query->orderBy($field, $dir);
                }

                $randomBoxes = $query->paginate(20);

                return [
                    'template' => 'random',
                    'data' => $randomBoxes,
                    'is_random_box' => true,
                ];
            }
        );
    }

    public function getRandomBoxDetail(Request $request, $categorySlug, $boxId)
    {
        $category = Category::where('slug', $categorySlug)->first();

        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $randomBox = RandomBox::where('id', $boxId)
            ->where('category_id', $category->id)
            ->where('is_public', true)
            ->withCount(['randomNicks as available_nicks_count' => function ($query) {
                $query->where('status', 'available');
            }])
            ->first();

        if (! $randomBox) {
            return response()->json(['message' => 'Random box not found'], 404);
        }

        $queryParams = $request->query();
        ksort($queryParams);
        $cacheKey = ApiCache::key(
            'nick-random-box-detail',
            $randomBox->id,
            json_encode($queryParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return ApiCache::rememberJson(
            'public:nick',
            $cacheKey,
            60,
            function () use ($request, $randomBox) {
                $orders = $this->parseSort($request);

                $query = RandomNick::select('id', 'status')
                    ->where('random_box_id', $randomBox->id)
                    ->where('status', 'available');

                foreach ($orders as [$field, $dir]) {
                    $query->orderBy($field, $dir);
                }

                $randomNicks = $query->paginate(20);

                return [
                    'template' => 'random_detail',
                    'box' => $randomBox,
                    'data' => $randomNicks,
                    'is_random_detail' => true,
                ];
            }
        );
    }

    public function buyRandom(Request $request, $categorySlug, $boxId)
    {
        return $this->purchaseRandom($request, $categorySlug, (int)$boxId, null);
    }

    public function buySpecificNick(Request $request, $categorySlug, $boxId, $nickId)
    {
        return $this->purchaseRandom($request, $categorySlug, (int)$boxId, (int)$nickId);
    }

    private function purchaseRandom(Request $request, string $slug, int $boxId, ?int $nickId)
    {
        $data = $request->validate(['idempotency_key' => ['nullable', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_-]+$/']]);
        try {
            return response()->json(app(\App\Services\RandomPurchaseService::class)->purchase(
                (int)$request->user()->id, $slug, $boxId, $nickId, $data['idempotency_key'] ?? null,
            ));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            return response()->json(['message' => $error->getMessage(),
                'error_code' => $error->getStatusCode() === 400 ? 'INSUFFICIENT_BALANCE' : 'PURCHASE_REJECTED'], $error->getStatusCode());
        } catch (\Throwable $error) {
            Log::error('Random purchase could not be confirmed', ['exception' => get_class($error)]);
            return response()->json(['message' => 'Chưa xác nhận kết quả. Kiểm tra lịch sử hoặc thử lại cùng lần mua.',
                'error_code' => 'PURCHASE_UNCONFIRMED'], 500);
        }
    }

    public function show($id)
    {
        $cacheKey = ApiCache::key('nick-detail', $id);

        $cached = ApiCache::rememberJson(
            'public:nick',
            $cacheKey,
            120,
            function () use ($id) {
                $nick = Nick::select([
                    'snapshot_id',
                    'id',
                    'price',
                    'description',
                    'image',
                    'listing_type',
                    'attribute_cache_json',
                    'category_id',
                ])
                    ->with(['snapshot', 'category:id,name,slug'])
                    ->find($id);

                if (! $nick) {
                    return null;
                }

                // Lấy toàn bộ media của nick
                $images = $nick->getMedia('images')->toBase()->map(function ($media) {
                    return [
                        'url' => $media->getUrl(),
                    ];
                });

                if ($nick->image && ! $images->contains('url', $nick->image)) {
                    $images->prepend(['url' => $nick->image]);
                }

                $relatedNicks = Nick::select([
                    'snapshot_id',
                    'id',
                    'price',
                    'description',
                    'image',
                    'listing_type',
                    'attribute_cache_json',
                ])
                    ->with('snapshot:id,summary_json')
                    ->where('id', '!=', $nick->id)
                    ->where('category_id', $nick->category_id)
                    ->where('status', 'not_sold')
                    ->whereBetween('price', [
                        $nick->price * 0.8,
                        $nick->price * 1.2,
                    ])
                    ->limit(10)
                    ->get();

                return [
                    'data' => [
                        'id' => $nick->id,
                        'price' => $nick->price,
                        'description' => $nick->description,
                        'image' => $nick->image,
                        'listing_type' => $nick->listing_type,
                        'attribute_cache_json' => $nick->attribute_cache_json ?? '{}',
                        'images' => $images,
                        'category' => $nick->category ? ['name' => $nick->category->name, 'slug' => $nick->category->slug] : null,
                        'nro_summary' => $nick->snapshot?->summary_json,
                        'nro_snapshot' => $nick->snapshot ? ['data' => $nick->snapshot->data_json, 'completeness' => $nick->snapshot->completeness_json, 'summary' => $nick->snapshot->summary_json] : null,
                        'related' => NickResource::collection($relatedNicks),
                    ],
                ];
            }
        );

        if (! $cached) {
            return response()->json([
                'message' => 'Nick not found',
            ], 404);
        }

        return response()->json($cached);
    }

    public function purchase(Request $request)
    {
        $validated = $request->validate([
            'productId' => 'required|integer|exists:nicks,id',
            'voucherCode' => 'nullable|string',
        ]);
        $buyerId = (int) $request->user()->id;
        $productId = (int) $validated['productId'];
        $result = null;
        $orderId = null;
        try {
            $result = DB::transaction(function () use ($buyerId, $productId, &$result, &$orderId) {
                $nick = Nick::withoutGlobalScope(UserOwnedScope::class)->whereKey($productId)->lockForUpdate()->firstOrFail();
                // Stable order for both money rows, including purchases in opposite directions.
                $users = User::whereIn('id', array_unique([$buyerId, (int) $nick->user_id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $buyer = $users->get($buyerId);
                $seller = $users->get($nick->user_id);
                abort_unless($buyer && $seller, 404, 'Tài khoản giao dịch không còn tồn tại.');
                abort_if($buyer->isLocked(), 403, 'Tài khoản đã bị khóa.');
                abort_if($buyer->roles()->exists(), 403, 'Bạn là cộng tác viên không được mua nick nhé');
                abort_if($buyerId === (int) $nick->user_id, 403, 'Không thể tự mua nick của mình.');

                $order = NickOrder::where('nick_id', $nick->id)->where('buyer_id', $buyerId)->where('status', 'completed')->first();
                if ($nick->status !== 'not_sold') {
                    // A lost success response can be recovered by the original buyer only.
                    abort_unless($nick->status === 'sold' && $order, 404, 'Nick không có sẵn');
                } else {
                    abort_if($order !== null, 409, 'Đơn đã tồn tại, vui lòng kiểm tra lịch sử mua.');
                    abort_if(bccomp((string) $buyer->balance, (string) $nick->price, 2) < 0, 400, 'Số dư không đủ');
                    // Fail before charging if credentials cannot be delivered.
                    AccountEncrypt::decrypt($nick->account_password);
                    if ($nick->game_account_id) {
                        $gameAccount = \App\Models\NroAccount::whereKey($nick->game_account_id)->lockForUpdate()->firstOrFail();
                        abort_if(DB::table('nro_worker_jobs')->where('account_id', $gameAccount->id)->whereIn('status', ['queued', 'processing', 'review'])->exists(), 409, 'Nick đang được kiểm tra thông tin. Vui lòng thử lại sau.');
                        $gameAccount->update(['status' => 'sold', 'game_password' => null]);
                    }
                    $price = (int) $nick->price;
                    $buyerOldBalance = (int) $buyer->balance;
                    $sellerOldBalance = (int) $seller->balance;
                    $buyer->decrement('balance', $price);
                    $seller->increment('balance', $price);
                    TransactionService::log(userId: $buyerId, type: 'buy_nick', amount: -$price,
                        description: "Mua nick #{$nick->id}", performedBy: $buyerId, related: $nick, relatedId: $nick->id,
                        oldBalance: $buyerOldBalance, newBalance: $buyerOldBalance - $price,
                        idempotencyKey: "nick-purchase:{$nick->id}:buyer:{$buyerId}",
                        metadata: ['source' => 'api', 'role' => 'buyer', 'seller_id' => $seller->id]);
                    TransactionService::log(userId: $seller->id, type: 'sell_nick', amount: $price,
                        description: "Bán nick #{$nick->id} cho user #{$buyerId}", performedBy: $buyerId, related: $nick, relatedId: $nick->id,
                        oldBalance: $sellerOldBalance, newBalance: $sellerOldBalance + $price,
                        idempotencyKey: "nick-purchase:{$nick->id}:seller:{$seller->id}",
                        metadata: ['source' => 'api', 'role' => 'seller', 'buyer_id' => $buyerId]);
                    $nick->update(['status' => 'sold']);
                    $order = NickOrder::create(['nick_id' => $nick->id, 'buyer_id' => $buyerId, 'seller_id' => $seller->id,
                        'price' => $price, 'commission' => 0, 'status' => 'completed']);
                }
                $balance = \App\Services\UserBalanceSnapshot::read($buyerId);
                $orderId = $order->id;
                return $result = [
                    'success' => true, 'message' => 'Mua nick thành công',
                    'nick' => ['id' => $nick->id, 'account' => $nick->account_name,
                        'password' => AccountEncrypt::decrypt($nick->account_password), 'description' => $nick->description],
                    'box' => ['id' => 0, 'name' => $nick->category->name ?? 'Nick Game'],
                    'transaction' => ['order_id' => $order->id, 'amount_paid' => $order->price,
                        'remaining_balance' => $balance['balance'], 'balance_revision' => $balance['balance_revision']],
                ];
            }, 3);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Nick không có sẵn'], 404);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            // An after-commit observer may fail even though the financial transaction succeeded.
            $committed = false;
            try {
                $committed = $result && $orderId && NickOrder::whereKey($orderId)->where('buyer_id', $buyerId)->where('status', 'completed')->exists();
            } catch (\Throwable) {}
            Log::error('Nick purchase error', ['nick_id' => $productId, 'order_id' => $orderId, 'committed' => $committed, 'error' => $e->getMessage()]);
            if (!$committed) return response()->json(['message' => 'Chưa xác nhận được kết quả mua. Vui lòng kiểm tra lịch sử hoặc thử lại cùng nick.'], 500);
        }
        try {
            ApiCache::clearGroups(['public:nick']);
        } catch (\Throwable $e) {
            Log::warning('Nick purchase cache invalidation failed', ['nick_id' => $productId, 'error' => $e->getMessage()]);
        }
        return response()->json($result);
    }
}
