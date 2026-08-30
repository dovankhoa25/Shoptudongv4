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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NickController extends Controller
{
    public function getByCategory(Request $request, $slug)
    {
        $category = Category::with('attributes.options')
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
        $orderBys = $this->parseSort($request);

        $query = Nick::query()
            ->select([
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

    private function handleSpin(Request $request, Category $category)
    {
        $orderBys = $this->parseSort($request);

        $query = Spin::where('category_id', $category->id);

        foreach ($orderBys as [$field, $direction]) {
            $query->orderBy($field, $direction);
        }

        $spins = $query->paginate(20);

        return response()->json([
            'template' => 'spin',
            'data' => $spins,
            'is_spin' => true,
        ]);
    }

    private function handleRandom(Request $request, Category $category)
    {
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

        return response()->json([
            'template' => 'random',
            'data' => $randomBoxes,
            'is_random_box' => true,
        ]);
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

        $orders = $this->parseSort($request);

        $query = RandomNick::select('id', 'status')
            ->where('random_box_id', $randomBox->id)
            ->where('status', 'available');

        foreach ($orders as [$field, $dir]) {
            $query->orderBy($field, $dir);
        }

        $randomNicks = $query->paginate(20);

        return response()->json([
            'template' => 'random_detail',
            'box' => $randomBox,
            'data' => $randomNicks,
            'is_random_detail' => true,
        ]);
    }

    // Method để mua random (chọn ngẫu nhiên)
    public function buyRandom(Request $request, $categorySlug, $boxId)
    {
        $category = Category::where('slug', $categorySlug)->first();
        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        try {
            $buyer = $request->user();

            $randomBox = RandomBox::where('id', $boxId)
                ->where('category_id', $category->id)
                ->where('is_public', true)
                ->first();

            if (! $randomBox) {
                return response()->json(['message' => 'Random box not found'], 404);
            }

            // Bắt đầu transaction
            DB::beginTransaction();

            // Lock nick ngẫu nhiên đang còn available
            $randomNick = RandomNick::where('random_box_id', $randomBox->id)
                ->where('status', 'available')
                ->inRandomOrder()
                ->lockForUpdate() // ✅ Lock để tránh race condition
                ->first();

            if (! $randomNick) {
                DB::rollBack();

                return response()->json(['message' => 'Đã hết Phần quà'], 400);
            }

            // Kiểm tra số dư
            if ($buyer->balance < $randomBox->price) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Số dư không đủ',
                    'error_code' => 'INSUFFICIENT_BALANCE',
                    'required' => $randomBox->price,
                    'current' => $buyer->balance,
                ], 400);
            }

            // Cập nhật nick
            $randomNick->update([
                'status' => 'taken',
                'buyer_id' => $buyer->id,
                'purchased_at' => now(),
            ]);

            // Trừ tiền
            $buyerOldBalance = (int) $buyer->balance;
            $buyer->decrement('balance', $randomBox->price);
            $buyerNewBalance = $buyerOldBalance - (int) $randomBox->price;
            // Log buyer
            TransactionService::log(
                userId: $buyer->id,
                type: 'buy_random',
                amount: -$randomBox->price,
                description: "Mua random nick #{$randomNick->id} từ box #{$randomBox->id}",
                performedBy: $buyer->id,
                related: $randomNick,
                relatedId: $randomNick->id,
                oldBalance: $buyerOldBalance,
                newBalance: $buyerNewBalance,
                idempotencyKey: "random-nick-purchase:{$randomNick->id}:buyer:{$buyer->id}",
                metadata: [
                    'source' => 'api',
                    'random_box_id' => $randomBox->id,
                    'role' => 'buyer',
                ],
            );

            RandomOrder::create([
                'user_id' => $buyer->id,
                'random_nick_id' => $randomNick->id,
                'price' => $randomBox->price,
            ]);

            // Nếu có seller, cộng và log
            if ($randomNick->user_id) {
                $seller = User::where('id', $randomNick->user_id)->lockForUpdate()->first();
                if ($seller) {
                    $sellerOldBalance = (int) $seller->balance;
                    $seller->increment('balance', $randomBox->price);
                    $sellerNewBalance = $sellerOldBalance + (int) $randomBox->price;

                    TransactionService::log(
                        userId: $seller->id,
                        type: 'sell_random',
                        amount: $randomBox->price,
                        description: "Bán random nick #{$randomNick->id} cho user #{$buyer->id}",
                        performedBy: $buyer->id,
                        related: $randomNick,
                        relatedId: $randomNick->id,
                        oldBalance: $sellerOldBalance,
                        newBalance: $sellerNewBalance,
                        idempotencyKey: "random-nick-purchase:{$randomNick->id}:seller:{$seller->id}",
                        metadata: [
                            'source' => 'api',
                            'random_box_id' => $randomBox->id,
                            'role' => 'seller',
                            'buyer_id' => $buyer->id,
                        ],
                    );
                }
            }
            DB::commit();

            return response()->json([
                'message' => 'Purchase successful',
                'nick' => [
                    'id' => $randomNick->id,
                    'account' => $randomNick->account,
                    'password' => $randomNick->password,
                    'description' => $randomNick->description,
                    'purchased_at' => $randomNick->purchased_at,
                ],
                'box' => [
                    'id' => $randomBox->id,
                    'name' => $randomBox->name,
                    'price' => $randomBox->price,
                ],

            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in buyRandom: '.$e->getMessage());

            return response()->json(['message' => 'Transaction failed', 'error_code' => 'TRANSACTION_FAILED'], 500);
        }
    }

    // Method để mua nick theo ID cụ thể - NEW
    public function buySpecificNick(Request $request, $categorySlug, $boxId, $nickId)
    {
        try {
            $category = Category::where('slug', $categorySlug)->first();
            if (! $category) {
                return response()->json([
                    'message' => 'Category not found',
                    'error_code' => 'CATEGORY_NOT_FOUND',
                ], 404);
            }

            $buyer = $request->user();
            if (! $buyer) {
                return response()->json([
                    'message' => 'yêu cầu đăng nhập',
                    'error_code' => 'UNAUTHORIZED',
                ], 401);
            }

            $randomBox = RandomBox::where('id', $boxId)
                ->where('category_id', $category->id)
                ->where('is_public', true)
                ->first();

            if (! $randomBox) {
                return response()->json([
                    'message' => 'Random box not found',
                    'error_code' => 'BOX_NOT_FOUND',
                ], 404);
            }

            if ($buyer->balance < $randomBox->price) {
                return response()->json([
                    'message' => 'số dư không đủ',
                    'error_code' => 'INSUFFICIENT_BALANCE',
                    'required' => $randomBox->price,
                    'current' => $buyer->balance,
                ], 400);
            }

            DB::beginTransaction();

            try {
                // Lock record tại đây - bên trong transaction
                $specificNick = RandomNick::where('id', $nickId)
                    ->where('random_box_id', $randomBox->id)
                    ->lockForUpdate()
                    ->first();

                if (! $specificNick) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'Nick not found in this box',
                        'error_code' => 'NICK_NOT_FOUND',
                    ], 404);
                }

                if ($specificNick->status !== 'available') {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'Nick đã bị mua rồi',
                        'error_code' => 'NICK_NOT_AVAILABLE',
                        'current_status' => $specificNick->status,
                    ], 400);
                }

                $specificNick->update([
                    'status' => 'taken',
                    'buyer_id' => $buyer->id,
                    'purchased_at' => now(),
                ]);

                $buyerOldBalance = (int) $buyer->balance;
                $buyer->decrement('balance', $randomBox->price);
                $buyerNewBalance = $buyerOldBalance - (int) $randomBox->price;
                // log buyer
                TransactionService::log(
                    userId: $buyer->id,
                    type: 'buy_random_specific',
                    amount: -$randomBox->price,
                    description: "Mua cụ thể random nick #{$specificNick->id} từ box #{$randomBox->id}",
                    performedBy: $buyer->id,
                    related: $specificNick,
                    relatedId: $specificNick->id,
                    oldBalance: $buyerOldBalance,
                    newBalance: $buyerNewBalance,
                    idempotencyKey: "random-nick-specific-purchase:{$specificNick->id}:buyer:{$buyer->id}",
                    metadata: [
                        'source' => 'api',
                        'random_box_id' => $randomBox->id,
                        'role' => 'buyer',
                    ],
                );

                RandomOrder::create([
                    'user_id' => $buyer->id,
                    'random_nick_id' => $nickId,
                    'price' => $randomBox->price,
                ]);

                // ✅ Cộng tiền cho người đăng nick (nếu có user_id)
                // seller cộng và log
                if ($specificNick->user_id) {
                    $seller = User::where('id', $specificNick->user_id)->lockForUpdate()->first();
                    if ($seller) {
                        $sellerOldBalance = (int) $seller->balance;
                        $seller->increment('balance', $randomBox->price);
                        $sellerNewBalance = $sellerOldBalance + (int) $randomBox->price;

                        TransactionService::log(
                            userId: $seller->id,
                            type: 'sell_random_specific',
                            amount: $randomBox->price,
                            description: "Bán random nick #{$specificNick->id} cho user #{$buyer->id}",
                            performedBy: $buyer->id,
                            related: $specificNick,
                            relatedId: $specificNick->id,
                            oldBalance: $sellerOldBalance,
                            newBalance: $sellerNewBalance,
                            idempotencyKey: "random-nick-specific-purchase:{$specificNick->id}:seller:{$seller->id}",
                            metadata: [
                                'source' => 'api',
                                'random_box_id' => $randomBox->id,
                                'role' => 'seller',
                                'buyer_id' => $buyer->id,
                            ],
                        );
                    }
                }

                DB::commit();

                return response()->json([
                    'message' => 'Nick purchased successfully',
                    'nick' => [
                        'id' => $specificNick->id,
                        'account' => $specificNick->account,
                        'password' => $specificNick->password,
                        'description' => $specificNick->description,
                        'purchased_at' => $specificNick->purchased_at,
                    ],
                    'box' => [
                        'id' => $randomBox->id,
                        'name' => $randomBox->name,
                        'price' => $randomBox->price,
                    ],

                ], 200);
            } catch (\Exception $e) {
                DB::rollBack();

                return response()->json([
                    'message' => 'Transaction failed. Please try again.',
                    'error_code' => 'TRANSACTION_FAILED',
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Unexpected error in buySpecificNick: '.$e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred',
                'error_code' => 'INTERNAL_ERROR',
            ], 500);
        }
    }

    public function show($id)
    {
        $nick = Nick::select([
            'id',
            'price',
            'description',
            'image',
            'listing_type',
            'attribute_cache_json',
            'category_id',
        ])
            ->find($id);

        if (! $nick) {
            return response()->json([
                'message' => 'Nick not found',
            ], 404);
        }

        // Lấy toàn bộ media của nick
        $images = $nick->getMedia('images')->map(function ($media) {
            return [
                'url' => $media->getUrl(),
                // 'name' => $media->name,
                // 'id' => $media->id
            ];
        });
        $relatedNicks = Nick::select([
            'id',
            'price',
            'description',
            'image',
            'listing_type',
            'attribute_cache_json',
        ])
            ->where('category_id', $nick->category_id)
            ->where('status', 'not_sold')
            ->whereBetween('price', [
                $nick->price * 0.8,
                $nick->price * 1.2,
            ])
            ->limit(10)
            ->get();

        return response()->json([
            'data' => [
                'id' => $nick->id,
                'price' => $nick->price,
                'description' => $nick->description,
                'image' => $nick->image,
                'listing_type' => $nick->listing_type,
                'attribute_cache_json' => $nick->attribute_cache_json,
                'images' => $images,
                'related' => NickResource::collection($relatedNicks),
            ],
        ]);
    }

    public function purchase(Request $request)
    {
        $request->validate([
            'productId' => 'required|exists:nicks,id',
            'voucherCode' => 'nullable|string',
        ]);

        $buyer = $request->user();

        try {
            DB::beginTransaction();
            $existingNick = Nick::where('account_name', $request->input('account_name'))
                ->where('status', 'not_sold')
                ->first();

            if ($existingNick) {
                return redirect()->back()->withInput()->with('error', 'Tài khoản đã tồn tại và đang trong trạng thái chưa bán.');
            }
            // Khóa row tránh mua trùng
            $nick = Nick::with('user')->withoutGlobalScope(UserOwnedScope::class)
                ->where('id', $request->productId)
                ->where('status', 'not_sold')
                ->lockForUpdate()
                ->firstOrFail();

            if ($buyer->roles()->exists()) {
                return response()->json(['message' => 'Bạn Là Cộng tác viên không được mua nick nhé'], 403);
            }

            if (bccomp($buyer->balance, $nick->price, 2) < 0) {
                return response()->json(['message' => 'số dư không đủ'], 400);
            }
            $buyerOldBalance = $buyer->balance;
            // Trừ tiền Buyer an toàn
            $affected = User::where('id', $buyer->id)
                ->where('balance', '>=', $nick->price)
                ->decrement('balance', $nick->price);

            if (! $affected) {
                DB::rollBack();

                return response()->json(['success' => false, 'message' => 'Số dư không đủ'], 400);
            }
            $buyerNewBalance = $buyerOldBalance - $nick->price;
            // Ghi lịch sử cho buyer (âm)
            TransactionService::log(
                userId: $buyer->id,
                type: 'buy_nick',
                amount: -$nick->price,
                description: "Mua nick #{$nick->id}",
                performedBy: $buyer->id,
                related: $nick,
                relatedId: $nick->id,
                oldBalance: $buyerOldBalance,
                newBalance: $buyerNewBalance,
                idempotencyKey: "nick-purchase:{$nick->id}:buyer:{$buyer->id}",
                metadata: [
                    'source' => 'api',
                    'role' => 'buyer',
                    'seller_id' => $nick->user_id,
                ],
            );

            // Lấy seller
            $seller = $nick->user()->lockForUpdate()->first();
            if (! $seller) {
                DB::rollBack();

                return response()->json(['success' => false, 'message' => 'Trang không tồn tại'], 404);
            }
            // Lưu balance CŨ của seller
            $sellerOldBalance = $seller->balance;
            $seller->increment('balance', $nick->price);
            // Ghi lịch sử cho seller (dương)
            // Tính balance MỚI của seller
            $sellerNewBalance = $sellerOldBalance + $nick->price;
            TransactionService::log(
                userId: $seller->id,
                type: 'sell_nick',
                amount: $nick->price,
                description: "Bán nick #{$nick->id} cho user #{$buyer->id}",
                performedBy: $buyer->id,
                related: $nick,
                relatedId: $nick->id,
                oldBalance: $sellerOldBalance,
                newBalance: $sellerNewBalance,
                idempotencyKey: "nick-purchase:{$nick->id}:seller:{$seller->id}",
                metadata: [
                    'source' => 'api',
                    'role' => 'seller',
                    'buyer_id' => $buyer->id,
                ],
            );
            $nick->status = 'sold';
            $nick->save();

            // Tạo order
            $order = NickOrder::create([
                'nick_id' => $nick->id,
                'buyer_id' => $buyer->id,
                'seller_id' => $seller->id,
                'price' => $nick->price,
                'commission' => 0,
                'status' => 'completed',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Mua nick thành công',
                'nick' => [
                    'id' => $nick->id,
                    'account' => $nick->account_name,
                    'password' => AccountEncrypt::decrypt($nick->account_password),
                    'description' => $nick->description,
                ],
                'box' => [
                    'id' => 0,
                    'name' => $nick->category->name ?? 'Nick Game',
                ],
                'transaction' => [
                    'order_id' => $order->id,
                    'amount_paid' => $nick->price,
                    'remaining_balance' => $buyer->balance,
                ],
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();

            return response()->json(['message' => 'Nick không có sẵn'], 404);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Purchase error:', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'mua nicks thất bại do lỗi server'], 500);
        }
    }
}
