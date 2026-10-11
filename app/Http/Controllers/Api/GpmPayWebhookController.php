<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GpmPayWebhookRequest;
use App\Models\AtmTopup;
use App\Services\Deposit\GpmPayWebhookService;
use App\Services\UserRealtimeNotifier;
use Illuminate\Http\JsonResponse;

class GpmPayWebhookController extends Controller
{
    public function __invoke(GpmPayWebhookRequest $request, GpmPayWebhookService $service, UserRealtimeNotifier $realtime): JsonResponse
    {
        $payload = $request->validated();

        $ignored = match (true) {
            ($payload['test'] ?? false) == true => 'verification',
            $payload['source'] !== 'REAL' => 'simulated',
            $payload['transferType'] !== 'in' => 'outgoing',
            ! in_array(config('services.bank_deposit.provider'), ['gpmpay', 'both'], true) => 'provider_disabled',
            default => null,
        };

        if ($ignored !== null) {
            return response()->json(['success' => true, 'credited' => false, 'ignored' => true, 'reason' => $ignored]);
        }

        $originalPayload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $result = $service->process($payload, $originalPayload);
        $topup = $result['topup'];

        if ($result['credited']) {
            $realtime->balanceChanged(
                userId: (int) $topup->user_id,
                amount: (int) $topup->amount,
                balance: $result['balance'],
                message: 'Bạn đã nạp thành công +'.number_format((int) $topup->amount).' VNĐ qua ngân hàng.',
            );
        }

        return response()->json([
            'success' => true,
            'credited' => $result['credited'],
            'duplicate' => ! $result['created'],
            'needs_review' => $topup->status === AtmTopup::STATUS_NEEDS_REVIEW,
            'status' => $topup->status,
            'transaction_id' => $topup->provider_transaction_id,
            'user_id' => $topup->user_id,
            'amount' => (int) $topup->amount,
            'balance' => $result['balance'],
        ]);
    }
}
