<?php

namespace App\Http\Controllers\Admin;

use App\Filters\NickFilter;
use App\Helpers\AccountEncrypt;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nick\StoreNickRequest;
use App\Http\Requests\Nick\UpdateNickRequest;
use App\Http\Resources\Nick\NickOrderResource;
use App\Http\Resources\Nick\NickResource;
use App\Models\Attribute;
use App\Models\AttributeOption;
use App\Models\Category;
use App\Models\Nick;
use App\Models\NickAttribute;
use App\Models\NickOrder;
use App\Models\User;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class NickController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $user = $request->user();
        $canViewAllAdminData = $user->canViewAllAdminData();

        if (
            $request->filled('user_id')
            && ! $canViewAllAdminData
            && (int) $request->integer('user_id') !== (int) $user->id
        ) {
            abort(403, 'Bạn chỉ được xem nick của chính mình.');
        }

        $filter = new NickFilter($request);

        $ownedNicks = Nick::query()
            ->when(! $canViewAllAdminData, fn ($query) => $query->where('user_id', $user->id));

        $nicks = (clone $ownedNicks)->with(['user', 'category'])
            ->when($request->filled('sort_by_price'), function ($q) use ($request) {
                $order = in_array($request->sort_by_price, ['asc', 'desc']) ? $request->sort_by_price : 'asc';
                $q->orderBy('price', $order);
            })
            ->when($request->filled('listing_type'), function ($q) use ($request) {
                $q->where('listing_type', $request->listing_type);
            })
            ->orderByDesc('created_at');

        $nicks = $filter->apply($nicks)->paginate(20)->withQueryString();

        // return Inertia::render('Admin/Nicks/Index', [
        //     'nicks'   => NickResource::collection($nicks),
        //     'filters' => $request->only(['search', 'status', 'listing_type', 'category_id', 'game_type_id', 'user_id'])
        // ]);
        // Trong controller index method
        return Inertia::render('Admin/Nicks/Index', [
            'nicks' => NickResource::collection($nicks),
            'categories' => Category::where('template', 'default')->get(),
            'filters' => $request->only(['search', 'status', 'listing_type', 'category_id', 'user_id', 'date_from', 'date_to']),
            'stats' => [
                'total_nicks' => (clone $ownedNicks)->count(),
                'sold_nicks' => (clone $ownedNicks)->where('status', 'sold')->count(),
                'not_sold_nicks' => (clone $ownedNicks)->where('status', 'not_sold')->count(),
                'deleted_nicks' => (clone $ownedNicks)->where('status', 'deleted')->count(),
                'returned_nicks' => (clone $ownedNicks)->where('status', 'return')->count(),
                'vip_nicks' => (clone $ownedNicks)->where('listing_type', 'vip')->count(),
                'normal_nicks' => (clone $ownedNicks)->where('listing_type', 'normal')->count(),
                'total_revenue' => (clone $ownedNicks)->where('status', 'sold')->sum('price'),
                'today_nicks' => (clone $ownedNicks)->whereDate('created_at', today())->count(),
                'today_revenue' => (clone $ownedNicks)->where('status', 'sold')->whereDate('created_at', today())->sum('price'),
                'avg_price' => (clone $ownedNicks)->avg('price') ?? 0,
            ],
        ]);
    }

    public function create(Request $request)
    {

        $user = $request->user();
        if ($user->canViewAllAdminData()) {
            $categories = Category::where('template', 'default')
                ->select('id', 'name', 'slug', 'template')
                ->get();
        } else {
            // User thường chỉ thấy categories được gán với can_post = true
            $categories = $user->categories()
                ->where('template', 'default')
                ->where('status', 'active')
                ->wherePivot('can_post', true)
                ->select('categories.id', 'categories.name', 'categories.slug', 'categories.template')
                ->get();
        }

        return Inertia::render('Admin/Nicks/Create', [
            'categories' => $categories,
        ]);
    }

    public function store(StoreNickRequest $request)
    {
        $user = $request->user();

        // Kiểm tra duplicate trước khi tạo
        $exists = Nick::where([
            'user_id' => Auth::id(),
            'account_name' => $request->input('account_name'),
            'category_id' => $request->input('category_id'),
            'status' => 'not_sold',
        ])->exists();

        if ($exists) {
            // return redirect()->back()->with('error', 'Nick đã tồn tại!');
            abort(403, 'Nick đã tồn tại!.');
        }
        $canPost = $user->canViewAllAdminData()
            || $user->categories()
                ->where('category_id', $request->input('category_id'))
                ->wherePivot('can_post', true)
                ->exists();

        if (! $canPost) {
            abort(403, 'Bạn không có quyền đăng trong danh mục này.');
        }
        DB::beginTransaction();

        $encryptedPassword = AccountEncrypt::encrypt($request->input('account_password'));
        try {
            // 1️⃣ Tạo Nick mới 1 lần duy nhất
            $nick = Nick::create([
                'category_id' => $request->input('category_id'),
                'account_name' => $request->input('account_name'),
                'account_password' => $encryptedPassword,
                'price' => $request->input('price'),
                'description' => $request->input('description'),
                'listing_type' => $request->input('listing_type', 'normal'),
                'user_id' => Auth::id(),
                'status' => 'not_sold',
            ]);

            // 2️⃣ Xử lý thuộc tính
            $payload = $request->input('attribute_cache_json', []);
            $attributeIDs = collect($payload)->pluck('attribute_id')->unique()->toArray();
            $optionIDs = collect($payload)->pluck('option_id')->unique()->toArray();

            // Lọc attribute phải thuộc category_id này
            $attributesMap = Attribute::whereIn('id', $attributeIDs)
                ->whereHas('categories', fn ($q) => $q->where('categories.id', $request->input('category_id')))
                ->get()
                ->keyBy('id');

            if (count($attributesMap) !== count($attributeIDs)) {
                throw new \Exception('Một số thuộc tính không thuộc danh mục đã chọn.');
            }

            $optionsMap = AttributeOption::whereIn('id', $optionIDs)->get()->keyBy('id');

            $insertRows = [];
            $attributeCache = [];

            foreach ($payload as $item) {
                $attributeID = $item['attribute_id'] ?? null;
                $optionID = $item['option_id'] ?? null;

                if ($attributeID && $optionID && isset($attributesMap[$attributeID]) && isset($optionsMap[$optionID])) {
                    $insertRows[] = [
                        'nick_id' => $nick->id,
                        'attribute_id' => $attributeID,
                        'attribute_option_id' => $optionID,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    // $attributeCache[] = [
                    //     'attribute_id' => $attributeID,
                    //     'attribute_name' => $attributesMap[$attributeID]->name,
                    //     'option_id' => $optionID,
                    //     'option_value' => $optionsMap[$optionID]->option_value,
                    // ];
                    $attributeCache[$attributesMap[$attributeID]->name] = $optionsMap[$optionID]->option_value;
                }
            }

            if (! empty($insertRows)) {
                NickAttribute::insert($insertRows);
                $nick->attribute_cache_json = json_encode($attributeCache);
            }

            // 3️⃣ Xử lý ảnh
            $mainImageUrl = null;

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $index => $uploadedFile) {
                    $media = $nick->addMedia($uploadedFile)->toMediaCollection('images');

                    // Nếu là file đầu tiên ➜ gán làm snapshot
                    if ($index === 0) {
                        $mainImageUrl = $media->getUrl();
                    }
                }
            }

            if ($request->filled('image_urls')) {
                $urls = json_decode($request->input('image_urls'), true);
                foreach ($urls as $index => $url) {
                    $media = $nick->addMediaFromUrl($url)->toMediaCollection('images');

                    if (! $mainImageUrl && $index === 0) {
                        $mainImageUrl = $media->getUrl();
                    }
                }
            }

            // Gán snapshot
            if ($mainImageUrl) {
                $nick->image = $mainImageUrl;
            }

            // 🔥 Lưu 1 lần cuối cùng
            $nick->save();

            DB::commit();

            return redirect()->back()->with('success', 'Nick created successfully!');
        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()->back()->with('error', 'Error: '.$e->getMessage());
        }
    }

    public function show($id)
    {
        $nick = Nick::with(['category', 'attributeOptions'])->find($id);

        if (! $nick) {
            return response()->json([
                'success' => false,
                'message' => 'Nick not found.',
            ], 404);
        }

        $this->authorize('view', $nick);

        $category = $nick->category;

        $categoryAttributes = [];

        if ($category) {
            $categoryAttributes = $category->attributes()->with('options')->get();
        }

        $attributesSelected = $nick->attributes()->with('options')->get()->map(function ($attribute) {
            $selectedOptionId = $attribute->pivot->attribute_option_id;

            $selectedOption = $attribute->options->where('id', $selectedOptionId)->first();

            return [
                'attribute_id' => $attribute->id,
                'attribute_name' => $attribute->name,
                'selected_option' => $selectedOption ? [
                    'id' => $selectedOption->id,
                    'option_value' => $selectedOption->option_value,
                ] : null,
            ];
        });

        // Làm gọn dữ liệu:
        $nickData = [
            'id' => $nick->id,
            'name' => $nick->name,
            'price' => $nick->price,
            'description' => $nick->description,
            'account_name' => $nick->account_name,
            'account_password' => $nick->account_password,
            'listing_type' => $nick->listing_type,
        ];

        $categoryData = $category ? [
            'id' => $category->id,
            'name' => $category->name,
        ] : null;

        $categoryAttributesData = $categoryAttributes->map(function ($attribute) {
            return [
                'id' => $attribute->id,
                'name' => $attribute->name,
                'status' => $attribute->status,
                'options' => $attribute->options->map(function ($option) {
                    return [
                        'id' => $option->id,
                        'option_value' => $option->option_value,
                        'status' => $option->status,
                    ];
                }),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'nick' => $nickData,
                'category' => $categoryData,
                'attribute_options' => $attributesSelected,
                'category_attributes' => $categoryAttributesData,
                'images' => $nick->getMedia('images')->map(fn ($m) => $m->getUrl()),
            ],
        ]);
    }

    public function update(UpdateNickRequest $request, Nick $nick)
    {
        $this->authorize('update', $nick);

        if ($nick->status !== 'not_sold') {
            return redirect()->back()->with('error', 'Không được phép chỉnh sửa Nick đã bán.');
        }

        DB::beginTransaction();

        try {
            if ($request->input('account_password') !== $nick->account_password) {
                $encryptedPassword = AccountEncrypt::encrypt($request->input('account_password'));
            } else {
                $encryptedPassword = $nick->account_password;
            }

            // ⚡ KHÔNG cập nhật category_id
            $nick->update([
                'account_name' => $request->input('account_name'),
                'account_password' => $encryptedPassword,
                'price' => $request->input('price'),
                'description' => $request->input('description'),
                'listing_type' => $request->input('listing_type', 'normal'),
            ]);

            // ⚡ Xác thực attribute phải thuộc category GỐC
            $payload = $request->input('attribute_cache_json', []);
            $attributeIDs = collect($payload)->pluck('attribute_id')->unique()->toArray();
            $optionIDs = collect($payload)->pluck('option_id')->unique()->toArray();

            $attributesMap = Attribute::whereIn('id', $attributeIDs)
                ->whereHas('categories', fn ($q) => $q->where('categories.id', $nick->category_id))
                ->get()
                ->keyBy('id');

            if (count($attributesMap) !== count($attributeIDs)) {
                throw new \Exception('Một số thuộc tính không thuộc danh mục đã chọn.');
            }

            $optionsMap = AttributeOption::whereIn('id', $optionIDs)->get()->keyBy('id');

            // ⚡ Xoá + insert lại thuộc tính
            NickAttribute::where('nick_id', $nick->id)->delete();

            $insertRows = [];
            $attributeCache = [];

            foreach ($payload as $item) {
                $attributeID = $item['attribute_id'] ?? null;
                $optionID = $item['option_id'] ?? null;

                if ($attributeID && $optionID && isset($attributesMap[$attributeID]) && isset($optionsMap[$optionID])) {
                    $insertRows[] = [
                        'nick_id' => $nick->id,
                        'attribute_id' => $attributeID,
                        'attribute_option_id' => $optionID,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $attributeCache[$attributesMap[$attributeID]->name] = $optionsMap[$optionID]->option_value;
                }
            }

            if (! empty($insertRows)) {
                NickAttribute::insert($insertRows);
                $nick->attribute_cache_json = json_encode($attributeCache);
            }
            // Helper function để xóa media cũ (cả file vật lý)

            // ⚡ Xử lý ảnh mới nếu có
            $mainImageUrl = null;
            $deleteOldMedia = function () use ($nick) {
                $oldMedia = $nick->getMedia('images');
                foreach ($oldMedia as $media) {
                    $media->delete(); // Xóa cả DB record và file vật lý
                }
            };
            if ($request->hasFile('images')) {
                // Xóa tất cả ảnh cũ (cả file vật lý)
                $deleteOldMedia();

                foreach ($request->file('images') as $index => $uploadedFile) {
                    $media = $nick->addMedia($uploadedFile)->toMediaCollection('images');

                    if ($index === 0) {
                        $mainImageUrl = $media->getUrl();
                    }
                }
            }

            if ($request->filled('image_urls')) {
                $urls = json_decode($request->input('image_urls'), true);

                if ($urls && is_array($urls)) {
                    // Xóa tất cả ảnh cũ (cả file vật lý)
                    $deleteOldMedia();

                    foreach ($urls as $index => $url) {
                        $media = $nick->addMediaFromUrl($url)->toMediaCollection('images');

                        if (! $mainImageUrl && $index === 0) {
                            $mainImageUrl = $media->getUrl();
                        }
                    }
                }
            }

            // Xóa ảnh cũ trong trường image nếu có ảnh mới
            if ($mainImageUrl) {
                // Xóa file cũ từ storage nếu tồn tại
                if ($nick->image && Storage::exists($nick->image)) {
                    Storage::delete($nick->image);
                }
                $nick->image = $mainImageUrl;
            }

            $nick->save();

            DB::commit();

            return redirect()->back()->with('success', 'Nick updated successfully!');
        } catch (\Exception $e) {
            DB::rollBack();

            return redirect()->back()->with('error', 'Error: '.$e->getMessage());
        }
    }

    public function destroy($id)
    {
        $nick = Nick::findOrFail($id);
        $this->authorize('delete', $nick);

        if (in_array($nick->status, ['deleted', 'sold', 'return'])) {
            return back()->with('info', 'Chỉ có thể xóa nick ở trạng thái chưa bán.');
        }
        $nick->status = 'deleted';
        $nick->save();

        NickAttribute::where('nick_id', $nick->id)->delete();

        // $nick->clearMediaCollection('images');
        // Xóa media kèm files trong storage
        $nick->getMedia('images')->each(function ($media) {
            $media->delete(); // Này sẽ xóa cả file vật lý
        });

        return back()->with('success', 'Xoá nick thành công!');
    }

    public function history(Request $request)
    {
        $user = $request->user();
        $canViewAllAdminData = $user->canViewAllAdminData();

        $query = NickOrder::with(['nick.category', 'buyer', 'seller']);

        // Nếu không phải admin thì chỉ xem được đơn hàng của mình
        if (! $canViewAllAdminData) {
            $query->where('seller_id', $user->id);
        }

        // Tạo base query để tính stats (cùng điều kiện với main query)
        $statsQuery = NickOrder::query();
        if (! $canViewAllAdminData) {
            $statsQuery->where('seller_id', $user->id);
        }

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $searchCondition = function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                    ->orWhereHas('nick', function ($nickQuery) use ($search) {
                        $nickQuery->where('account_name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('buyer', function ($buyerQuery) use ($search) {
                        $buyerQuery->where('username', 'like', "%{$search}%");
                    })
                    ->orWhereHas('seller', function ($sellerQuery) use ($search) {
                        $sellerQuery->where('username', 'like', "%{$search}%");
                    });
            };

            $query->where($searchCondition);
            $statsQuery->where($searchCondition);
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->status);
            $statsQuery->where('status', $request->status);
        }

        // Buyer filter
        if ($request->filled('buyer_id')) {
            $query->where('buyer_id', $request->buyer_id);
            $statsQuery->where('buyer_id', $request->buyer_id);
        }

        // Seller filter
        if ($request->filled('seller_id')) {
            if (! $canViewAllAdminData && (int) $request->integer('seller_id') !== (int) $user->id) {
                abort(403, 'Bạn chỉ được xem đơn nick của chính mình.');
            }

            $query->where('seller_id', $request->seller_id);
            $statsQuery->where('seller_id', $request->seller_id);
        }

        // Date filters
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
            $statsQuery->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
            $statsQuery->whereDate('created_at', '<=', $request->date_to);
        }

        // Order by created_at để nhóm theo ngày dễ hơn
        $orders = $query->orderBy('created_at', 'desc')->paginate(20)->withQueryString();

        // Calculate statistics
        $stats = [
            'total_orders' => $statsQuery->count(),
            'pending_orders' => (clone $statsQuery)->where('status', 'pending')->count(),
            'completed_orders' => (clone $statsQuery)->where('status', 'completed')->count(),
            'refunded_orders' => (clone $statsQuery)->where('status', 'refunded')->count(),
            'total_revenue' => (clone $statsQuery)->where('status', 'completed')->sum('price'),
            'today_orders' => (clone $statsQuery)->whereDate('created_at', today())->count(),
            'today_revenue' => (clone $statsQuery)->where('status', 'completed')->whereDate('created_at', Carbon::today())->sum('price'),
            'avg_order_value' => (clone $statsQuery)->where('status', 'completed')->avg('price') ?? 0,
        ];

        return Inertia::render('Admin/NickOrders/Index', [
            'orders' => NickOrderResource::collection($orders),
            'filters' => $request->only(['search', 'status', 'buyer_id', 'seller_id', 'date_from', 'date_to']),
            'stats' => $stats,
        ]);
    }

    public function refundNick(Request $request, NickOrder $order)
    {
        $request->validate([
            'status' => 'required|in:refunded',
            'penalty_amount' => 'nullable|numeric|min:0|max:'.$order->price,
        ]);

        if ($order->status === 'refunded') {
            return redirect()->back()
                ->with('info', 'nick đã được hoàn rồi');
        }
        $user = $request->user();
        if (! $user->canViewAllAdminData()) {
            return redirect()->back()
                ->with('error', 'không có quyền');
        }
        $penaltyAmount = floatval($request->input('penalty_amount', 0));

        DB::beginTransaction();
        try {
            $order = NickOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === 'refunded') {
                DB::rollBack();

                return redirect()->back()
                    ->with('info', 'nick đã được hoàn rồi');
            }

            // Lock user rows để tránh race condition
            $buyer = User::where('id', $order->buyer_id)->lockForUpdate()->first();
            $seller = User::where('id', $order->seller_id)->lockForUpdate()->first();

            $nick = $order->nick;

            // ---- Buyer + refund ----
            $buyerOldBalance = $buyer->balance;
            $buyer->balance += $order->price;
            $buyerNewBalance = $buyer->balance;
            $buyer->save();

            TransactionService::log(
                userId: $buyer->id,
                type: 'refund',
                amount: $order->price,
                description: "Hoàn tiền nick #{$nick->id} từ seller #{$seller->id}",
                performedBy: $seller->id,
                related: $nick,
                relatedId: $nick->id,
                oldBalance: $buyerOldBalance,
                newBalance: $buyerNewBalance,
                idempotencyKey: "nick-order-refund:{$order->id}:buyer:{$buyer->id}",
                metadata: [
                    'source' => 'admin',
                    'nick_order_id' => $order->id,
                    'role' => 'buyer',
                    'admin_user_id' => $request->user()->id,
                ],
            );

            // ---- Seller - minus ----
            $sellerOldBalance = $seller->balance;
            $seller->balance -= $order->price; // hoặc $order->price - commission nếu có
            $sellerNewBalance = $seller->balance;
            $seller->save();

            TransactionService::log(
                userId: $seller->id,
                type: 'refund',
                amount: -$order->price,
                description: "Bị trừ tiền do hoàn nick #{$nick->id} cho user #{$buyer->id}",
                performedBy: $buyer->id,
                related: $nick,
                relatedId: $nick->id,
                oldBalance: $sellerOldBalance,
                newBalance: $sellerNewBalance,
                idempotencyKey: "nick-order-refund:{$order->id}:seller:{$seller->id}",
                metadata: [
                    'source' => 'admin',
                    'nick_order_id' => $order->id,
                    'role' => 'seller',
                    'admin_user_id' => $request->user()->id,
                ],
            );
            // ---- Nếu có tiền phạt, seller bị trừ thêm ----
            if ($penaltyAmount > 0) {
                $sellerOldBalance2 = $seller->balance;
                $seller->balance -= $penaltyAmount;
                $sellerNewBalance2 = $seller->balance;
                $seller->save();

                TransactionService::log(
                    userId: $seller->id,
                    type: 'penalty_fee',
                    amount: -$penaltyAmount,
                    description: "Phí phạt do vi phạm trong giao dịch nick #{$nick->id} với user #{$buyer->id}",
                    performedBy: $buyer->id,
                    related: $nick,
                    relatedId: $nick->id,
                    oldBalance: $sellerOldBalance2,
                    newBalance: $sellerNewBalance2,
                    idempotencyKey: "nick-order-refund:{$order->id}:penalty:seller:{$seller->id}",
                    metadata: [
                        'source' => 'admin',
                        'nick_order_id' => $order->id,
                        'role' => 'seller',
                        'admin_user_id' => $request->user()->id,
                        'penalty_amount' => (int) $penaltyAmount,
                    ],
                );
            }

            // ---- Update order ----
            $order->update([
                'status' => 'refunded',
            ]);

            // ---- Nick về lại shop ----
            $nick->update([
                'status' => 'return',
            ]);

            DB::commit();

            $message = 'Hoàn tiền thành công';
            if ($penaltyAmount > 0) {
                $message .= ' (Đã áp dụng phí phạt: '.number_format($penaltyAmount, 0, ',', '.').'đ)';
            }

            return redirect()->back()->with('success', $message);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Có lỗi khi hoàn tiền',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
