<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TelegramService;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceOrderController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'service_id' => 'required|exists:services,id',
            'username'   => 'required|string|max:255',
            'password'   => 'required|string|max:255',
            'note'       => 'nullable|string',
            'fields'     => 'nullable|array',
        ]);

        $user = $request->user();

        DB::beginTransaction();

        try {
            // Lấy service
            $service = Service::findOrFail($validated['service_id']);

            // Kiểm tra số dư
            if ($user->balance < $service->default_price) {
                return response()->json([
                    'success' => false,
                    'message' => 'Số dư không đủ để đặt dịch vụ này.',
                ], 400);
            }
            if ($user->roles()->exists()) {
                return response()->json(['message' => 'Bạn Là Cộng tác viên không được mua nick nhé'], 403);
            }

            // Lấy các fields được khai báo cho service
            $fields = DB::table('field_service')
                ->join('fields', 'field_service.field_id', '=', 'fields.id')
                ->where('field_service.service_id', $service->id)
                ->select('fields.*')
                ->get();

            // Xác thực các trường required
            $fieldSnapshot = [];
            foreach ($fields as $field) {
                $key = $field->field_key;

                if ($field->required && !isset($validated['fields'][$key])) {
                    throw ValidationException::withMessages([
                        "fields.$key" => "Trường {$field->label} là bắt buộc.",
                    ]);
                }

                if (isset($validated['fields'][$key])) {
                    $fieldSnapshot[] = [
                        'label' => $field->label,
                        'key'   => $field->field_key,
                        'value' => $validated['fields'][$key],
                    ];
                }
            }

            // Chuẩn an toàn
            $affected = User::where('id', $user->id)
                ->where('balance', '>=', $service->default_price)
                ->decrement('balance', $service->default_price);
            if (!$affected) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Số dư không đủ để đặt dịch vụ này.',
                ], 400);
            }

            $balanceAfter = (int) User::query()->whereKey($user->id)->value('balance');
            $balanceBefore = $balanceAfter + (int) $service->default_price;

            // Tạo order
            $order = ServiceOrder::create([
                'service_id'        => $service->id,
                'user_id'           => $user->id,
                'receiver_id'       => null,
                'service_price'     => $service->default_price,
                'account'           => $validated['username'],
                'password'          => $validated['password'],
                'description'       => $validated['note'] ?? null,
                'field_values_json' => $fieldSnapshot,
                'status'            => 'pending',
            ]);
            // Lưu lịch sử giao dịch (nếu có)
            TransactionService::log(
                userId: $user->id,
                type: 'buy_service',
                amount: -abs($service->default_price),
                description: "Đặt dịch vụ: {$service->name} (order #{$order->id})",
                performedBy: $user->id,
                related: $order,
                relatedId: $order->id,
                oldBalance: $balanceBefore,
                newBalance: $balanceAfter,
                idempotencyKey: "service-order-purchase:{$order->id}:user:{$user->id}",
                metadata: [
                    'source' => 'api',
                    'service_id' => $service->id,
                    'account' => $order->account,
                ],
            );

            DB::commit();
            // Gửi thông báo Telegram qua queue
            (new TelegramService())->sendQueue(
                "
        🛒 <b>Đơn hàng dịch vụ mới</b>\n
        👤 Người mua: <b>{$user->username}</b>\n
        🧾 Dịch vụ: <b>{$service->name}</b>\n
        💰 Giá: " . number_format($service->default_price, 0, ',', '.') . " VNĐ\n
        📦 Mã đơn: #{$order->id}\n
        ⏰ " . now()->format('H:i d/m/Y')
            );
            return response()->json([
                'success' => true,
                'order'   => $order,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            report($e);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
