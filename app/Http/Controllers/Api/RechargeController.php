<?php

namespace App\Http\Controllers\Api;

use App\Events\UserEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CallbackRequest;
use App\Http\Requests\Api\RechargeRequest;
use App\Models\CardType;
use App\Services\Deposit\CardRechargeService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class RechargeController extends Controller
{
    public function __construct(private readonly CardRechargeService $recharges) {}

    public function store(RechargeRequest $request): JsonResponse
    {
        $cardType = CardType::query()
            ->where('telco', $request->validated('telco'))
            ->where('status', true)
            ->firstOrFail();

        try {
            $card = $this->recharges->submit(
                $request->user(),
                $cardType,
                $request->safe()->only(['amount', 'card_code', 'card_serial']),
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 503);
        }

        return response()->json([
            'success' => true,
            'message' => 'Đã tiếp nhận thẻ nạp.',
            'data' => [
                'id' => $card->id,
                'telco' => $cardType->telco,
                'declared_value' => (int) $card->declared_value,
                'amount_user' => (int) $card->amount_user,
                'status' => $card->status,
                'note' => $card->note,
                'created_at' => $card->created_at?->toISOString(),
            ],
        ], 201);
    }

    public function callback(CallbackRequest $request): JsonResponse
    {
        try {
            $result = $this->recharges->processCallback($request->validated());
        } catch (InvalidArgumentException) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid signature',
            ], 401);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Card not found',
            ], 404);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 503);
        }

        if ($result['duplicate']) {
            return response()->json([
                'success' => false,
                'duplicate' => true,
                'message' => 'Callback đã được xử lý trước đó.',
                'card_id' => $result['card']->id,
            ]);
        }

        if ($result['credited']) {
            try {
                broadcast(new UserEvent(
                    userId: (int) $result['card']->user_id,
                    type: 'update_balance',
                    message: 'Bạn đã nạp thẻ thành công +'.number_format((int) $result['card']->amount_user).' VNĐ.',
                    payload: [
                        'amount' => (int) $result['card']->amount_user,
                        'balance' => $result['balance'],
                    ],
                ));
            } catch (Throwable $exception) {
                Log::warning('Broadcast card deposit failed', [
                    'card_id' => $result['card']->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Callback processed',
            'card_id' => $result['card']->id,
            'status' => $result['card']->status,
            'credited_amount' => (int) $result['card']->amount_user,
        ]);
    }
}
