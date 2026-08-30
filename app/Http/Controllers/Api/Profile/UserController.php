<?php

namespace App\Http\Controllers\Api\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\UpdateAvatarRequest;
use App\Http\Resources\Api\ApiUserResource;
use App\Http\Resources\Api\BalanceHistoryResource;
use App\Http\Resources\Api\CardHistoryResource;
use App\Http\Resources\Api\ServiceOrderResource;
use App\Http\Resources\Profile\AuthProviderResource;
use App\Http\Resources\Profile\DeviceResource;
use App\Http\Resources\Profile\LoginAttemptResource;
use App\Http\Resources\Profile\NroAccountResource;
use App\Http\Resources\Profile\ProfileResource;
use App\Http\Resources\Profile\PunishmentResource;
use App\Http\Resources\Profile\SecurityLogResource;
use App\Http\Resources\Withdrawal\WithdrawalRequestResource;
use App\Models\Card;
use App\Models\RandomOrder;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSecurityLog;
use App\Models\WithdrawalRequest;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function show(Request $request): ProfileResource
    {
        return new ProfileResource($request->user()->load('roles:id,name'));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'user' => new ProfileResource($request->user()->load('roles:id,name')),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'username' => ['sometimes', 'string', 'min:3', 'max:191', Rule::unique('users')->ignore($user->id)],
            'email' => ['sometimes', 'nullable', 'email', 'max:191', Rule::unique('users')->ignore($user->id)],
            'avatar' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ]);

        if ($data === []) {
            throw ValidationException::withMessages([
                'profile' => ['Cần gửi ít nhất một trường: username, email hoặc avatar.'],
            ]);
        }

        DB::transaction(function () use ($user, $data): void {
            if (array_key_exists('email', $data) && $data['email'] !== $user->email) {
                $data['email_verified_at'] = null;
            }
            $user->update($data);
            $user->authProviders()->where('provider', 'password')->update([
                'provider_id' => $user->username,
                'provider_email' => $user->email,
                'provider_username' => $user->username,
            ]);
        });

        UserSecurityLog::create([
            'user_id' => $user->id, 'event' => 'profile_updated',
            'ip_address' => $request->ip(), 'user_agent' => $request->userAgent(),
            'meta' => ['changed_fields' => array_keys($data)],
        ]);

        return response()->json(['message' => 'Cập nhật hồ sơ thành công.', 'data' => new ProfileResource($user->fresh()->load('roles:id,name'))]);
    }

    public function providers(Request $request)
    {
        return AuthProviderResource::collection($request->user()->authProviders()->latest()->get());
    }

    public function devices(Request $request)
    {
        return DeviceResource::collection($request->user()->devices()->latest('last_seen_at')->get());
    }

    public function securityLogs(Request $request)
    {
        return SecurityLogResource::collection($request->user()->securityLogs()->latest()->paginate($this->perPage($request)));
    }

    public function loginAttempts(Request $request)
    {
        return LoginAttemptResource::collection($request->user()->loginAttempts()->latest()->paginate($this->perPage($request)));
    }

    public function punishments(Request $request)
    {
        return PunishmentResource::collection($request->user()->punishments()->latest()->paginate($this->perPage($request)));
    }

    public function nroAccounts(Request $request)
    {
        return NroAccountResource::collection($request->user()->nroAccounts()->latest()->paginate($this->perPage($request)));
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 15), 1), 100);
    }

    public function getUser(Request $request)
    {
        return new ApiUserResource($request->user());
    }

    public function getUserCardHistory(Request $request)
    {
        $user = $request->user();
        $cards = Card::where('user_id', $user->id)
            ->select([
                'declared_value',
                'amount_user',
                'discount_rate_at_time',
                'code',
                'serial',
                'status',
                'note',
            ])
            ->latest() // sắp xếp mới nhất
            ->paginate(15);

        return CardHistoryResource::collection($cards);
    }

    public function getUserBalanceHistory(Request $request)
    {
        $user = $request->user();
        $perPage = min(50, max(5, (int) $request->get('per_page', 15)));

        $query = Transaction::where('user_id', $user->id)
            ->with(['performer:id,username'])
            ->latest();

        if ($request->filled('type')) {
            $query->where('type', (string) $request->type);
        }

        return BalanceHistoryResource::collection($query->paginate($perPage));
    }

    public function getUserServiceHistory(Request $request)
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'in:pending,approved,rejected,completed'],
            'search' => ['nullable', 'string', 'max:191'],
        ]);

        $user = $request->user();
        $query = ServiceOrder::withoutReceiverOwnedScope()
            ->where('user_id', $user->id)
            ->with([
                'service:id,name,processing_time,warranty',
                'receiver:id,username',
            ]);

        if ($status = $validated['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($search = trim((string) ($validated['search'] ?? ''))) {
            $query->where(function ($query) use ($search): void {
                $query->where('account', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('service', fn ($serviceQuery) => $serviceQuery->where('name', 'like', "%{$search}%"));

                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }
            });
        }

        $orders = $query
            ->latest()
            ->paginate((int) ($validated['per_page'] ?? 10))
            ->withQueryString();

        return ServiceOrderResource::collection($orders);
    }

    public function getUserServiceStats(Request $request)
    {
        $query = ServiceOrder::withoutReceiverOwnedScope()
            ->where('user_id', $request->user()->id);

        $counts = (clone $query)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return response()->json([
            'data' => [
                'total' => (int) $counts->sum(),
                'pending' => (int) ($counts['pending'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'completed' => (int) ($counts['completed'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
                'total_spent' => (int) (clone $query)
                    ->whereIn('status', ['pending', 'approved', 'completed'])
                    ->sum('service_price'),
            ],
        ]);
    }

    public function cancelServiceOrder(Request $request, $id)
    {
        $request->validate([
            'cancel_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();

        DB::transaction(function () use ($request, $id, $user) {
            $order = ServiceOrder::where('id', $id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($order->status, ['approved', 'rejected', 'completed'])) {
                throw new \Exception('Đơn đã được duyệt hoặc huỷ hoặc hoàn thành, không thể huỷ thêm!');
            }

            $order->status = 'rejected';
            $order->description = trim($order->description.' | Huỷ đơn: '.$request->cancel_reason);
            $order->save();

            $affected = User::where('id', $order->user_id)
                ->lockForUpdate()
                ->increment('balance', $order->service_price);

            if (! $affected) {
                throw new \Exception('Cộng tiền thất bại!');
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Huỷ đơn thành công & hoàn tiền.',
        ]);
    }

    public function getUserRandomHistory(Request $request)
    {
        try {
            $user = $request->user();

            // Validate pagination params
            $perPage = min(50, max(5, $request->get('per_page', 10))); // 5-50 items per page
            $page = max(1, $request->get('page', 1));

            // Get query params
            $search = $request->get('search', '');
            $status = $request->get('status', ''); // available, taken, deleted
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');

            // Build query
            $query = RandomOrder::where('user_id', $user->id)
                ->with([
                    'randomNick' => function ($query) {
                        $query->select('id', 'random_box_id', 'account', 'password', 'description', 'status', 'created_at')
                            ->with([
                                'randomBox' => function ($boxQuery) {
                                    $boxQuery->select('id', 'name', 'category_id', 'image')
                                        ->with(['category:id,name,slug']);
                                },
                            ]);
                    },
                ]);

            // Apply search filter
            if ($search) {
                $query->whereHas('randomNick', function ($nickQuery) use ($search) {
                    $nickQuery->where('account', 'like', "%{$search}%")
                        ->orWhereHas('randomBox', function ($boxQuery) use ($search) {
                            $boxQuery->where('name', 'like', "%{$search}%");
                        });
                });
            }

            // Apply status filter
            if ($status) {
                $query->whereHas('randomNick', function ($nickQuery) use ($status) {
                    $nickQuery->where('status', $status);
                });
            }

            // Apply sorting
            $allowedSorts = ['created_at', 'price'];
            if (in_array($sortBy, $allowedSorts)) {
                $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
            } else {
                $query->latest();
            }

            $orders = $query->paginate($perPage);

            // Transform data
            $transformedData = $orders->map(function ($order) {
                return [
                    'id' => $order->id,
                    'price' => $order->price,
                    'purchased_at' => $order->created_at->toISOString(),
                    'nick' => [
                        'id' => $order->randomNick->id,
                        'account' => $order->randomNick->account,
                        'password' => $order->randomNick->password,
                        'description' => $order->randomNick->description,
                        'status' => $order->randomNick->status,
                        'box' => [
                            'id' => $order->randomNick->randomBox->id,
                            'name' => $order->randomNick->randomBox->name,
                            'image' => $order->randomNick->randomBox->image,
                            'category' => [
                                'id' => $order->randomNick->randomBox->category->id,
                                'name' => $order->randomNick->randomBox->category->name,
                                'slug' => $order->randomNick->randomBox->category->slug,
                            ],
                        ],
                    ],
                ];
            });

            return response()->json([
                'data' => $transformedData,
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'per_page' => $orders->perPage(),
                    'total' => $orders->total(),
                    'from' => $orders->firstItem(),
                    'to' => $orders->lastItem(),
                ],
                'filters' => [
                    'search' => $search,
                    'status' => $status,
                    'sort_by' => $sortBy,
                    'sort_order' => $sortOrder,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getUserRandomHistory: '.$e->getMessage());

            return response()->json([
                'message' => 'An error occurred while fetching random history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getUserRandomStats(Request $request)
    {
        try {
            $user = $request->user();

            $stats = [
                'total' => RandomOrder::where('user_id', $user->id)->count(),
                'total_spent' => RandomOrder::where('user_id', $user->id)->sum('price'),
                'this_month' => RandomOrder::where('user_id', $user->id)
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->count(),
                'active_nicks' => RandomOrder::where('user_id', $user->id)
                    ->whereHas('randomNick', function ($query) {
                        $query->where('status', 'taken');
                    })
                    ->count(),
            ];

            return response()->json([
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getUserRandomStats: '.$e->getMessage());

            return response()->json([
                'message' => 'An error occurred while fetching stats',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateAvatar(UpdateAvatarRequest $request)
    {
        try {
            DB::beginTransaction();

            $user = $request->user();

            // ✅ Sử dụng custom method từ model
            $media = $user->updateAvatarFromUpload($request->file('avatar'));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Cập nhật avatar thành công',
                'data' => [
                    'avatar' => $user->avatar, // Lấy trực tiếp từ column, không cần join
                    'avatar_url' => $user->avatar_url, // Sử dụng accessor
                    'updatedAt' => now()->toISOString(),
                ],
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi cập nhật avatar',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function deleteAvatar(Request $request)
    {
        try {
            $user = $request->user();
            $user->deleteAvatar();

            return response()->json([
                'success' => true,
                'message' => 'Đã xóa avatar',
                'data' => [
                    'avatar' => $user->avatar_url,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function getWithdrawal(Request $request)
    {
        $user = $request->user();

        $query = WithdrawalRequest::where('user_id', $user->id);

        // Filter by status
        if ($request->has('status') && $request->status !== '') {
            $query->where('status', $request->status);
        }

        // Search by bank info or amount
        if ($request->has('search') && $request->search !== '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('bank_name', 'like', '%'.$search.'%')
                    ->orWhere('bank_account_number', 'like', '%'.$search.'%')
                    ->orWhere('bank_account_name', 'like', '%'.$search.'%')
                    ->orWhere('amount', 'like', '%'.$search.'%');
            });
        }

        if ($request->has('from_date') && $request->from_date !== '') {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->has('to_date') && $request->to_date !== '') {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // Get limit from request, default to 15
        $limit = $request->input('limit', 5);

        $withdrawals = $query->orderByDesc('created_at')
            ->paginate($limit);

        return WithdrawalRequestResource::collection($withdrawals);
    }

    public function storeWithdrawal(Request $request)
    {

        $request->validate([
            'amount' => 'required|numeric|min:10000',
            'bank_name' => 'required|string|max:255',
            'bank_account_number' => 'required|string|max:100',
            'bank_account_name' => 'required|string|max:255',
            'note_user' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();

        try {
            $authUser = $request->user();

            // Lock bản ghi user để tránh race condition
            $user = User::where('id', $authUser->id)->lockForUpdate()->first();
            // Kiểm tra số dư
            if ($user->balance < $request->amount) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => 'Số dư không đủ để thực hiện rút tiền.',
                ], 400);
            }

            // Trừ tiền
            $balanceBefore = (int) $user->balance;
            $user->balance -= $request->amount;
            $user->save();
            $balanceAfter = (int) $user->balance;

            // Tạo yêu cầu rút tiền
            $withdrawal = WithdrawalRequest::create([
                'user_id' => $user->id,
                'amount' => $request->amount,
                'bank_name' => $request->bank_name,
                'bank_account_number' => $request->bank_account_number,
                'bank_account_name' => $request->bank_account_name,
                'note_user' => $request->note_user,
                'status' => 'pending',
            ]);

            TransactionService::log(
                userId: $user->id,
                type: 'withdraw',
                amount: -$request->amount,
                description: "Rút tiền web vàng | $request->note_user",
                performedBy: $user->id,
                related: $withdrawal,
                relatedId: $withdrawal->id,
                oldBalance: $balanceBefore,
                newBalance: $balanceAfter,
                idempotencyKey: "withdrawal-request:{$withdrawal->id}:debit",
                metadata: [
                    'source' => 'api-profile',
                    'bank_name' => $withdrawal->bank_name,
                    'status' => $withdrawal->status,
                ],
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Yêu cầu rút tiền đã được gửi',
                'data' => $withdrawal,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Yêu cầu rút không thành công',
            ], 400);
        }
    }
}
