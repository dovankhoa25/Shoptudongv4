<?php

namespace App\Http\Controllers\Api;

use App\Events\UserEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SepayWebhookRequest;
use App\Services\Deposit\SepayWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class WebhookController extends Controller
{
    public function __construct(private readonly SepayWebhookService $sepay) {}

    public function handle(SepayWebhookRequest $request): JsonResponse
    {
        $result = $this->sepay->process($request->validated());

        if (! $result['created']) {
            return response()->json([
                'success' => false,
                'duplicate' => true,
                'message' => 'Webhook đã được xử lý trước đó.',
                'transaction_id' => $result['topup']->provider_transaction_id,
            ]);
        }

        try {
            broadcast(new UserEvent(
                userId: (int) $result['topup']->user_id,
                type: 'update_balance',
                message: 'Bạn đã nạp thành công +'.number_format((int) $result['topup']->amount).' VNĐ qua ngân hàng.',
                payload: [
                    'amount' => (int) $result['topup']->amount,
                    'balance' => $result['balance'],
                ],
            ));
        } catch (Throwable $exception) {
            Log::warning('Broadcast bank deposit failed', [
                'topup_id' => $result['topup']->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Webhook processed',
            'user_id' => $result['topup']->user_id,
            'amount' => (int) $result['topup']->amount,
            'balance' => $result['balance'],
        ]);
    }
}
