<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\UpdateCarotRechargeRequest;
use App\Http\Resources\Api\CarotRechargeResource;
use App\Models\CarotRecharge;
use App\Models\CarotRechargeStatistic;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CarotRechargeController extends Controller
{
    public function pending(Request $request): JsonResponse
    {
        if (!$this->isAuthorizedApp($request)) {
            return $this->unauthorizedResponse();
        }

        $limit = min(100, max(1, (int) $request->get('limit', 20)));

        $recharges = CarotRecharge::where('status', CarotRecharge::STATUS_PENDING)
            ->oldest()
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => CarotRechargeResource::collection($recharges)->resolve(),
        ]);
    }

    public function markSuccess(UpdateCarotRechargeRequest $request, int $id): JsonResponse
    {
        if (!$this->isAuthorizedApp($request)) {
            return $this->unauthorizedResponse();
        }

        $result = DB::transaction(function () use ($request, $id) {
            $recharge = CarotRecharge::where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$this->canUpdateToStatus($recharge, CarotRecharge::STATUS_SUCCESS)) {
                return [
                    'success' => false,
                    'recharge' => $recharge,
                ];
            }

            $this->updateStatus($recharge, CarotRecharge::STATUS_SUCCESS, $request);

            return [
                'success' => true,
                'recharge' => $recharge->fresh(),
            ];
        });

        if (!$result['success']) {
            return $this->invalidStatusResponse($result['recharge']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cap nhat don nap carot thanh cong',
            'data' => (new CarotRechargeResource($result['recharge']))->resolve(),
        ]);
    }

    public function markFailed(UpdateCarotRechargeRequest $request, int $id): JsonResponse
    {
        if (!$this->isAuthorizedApp($request)) {
            return $this->unauthorizedResponse();
        }

        $result = DB::transaction(function () use ($request, $id) {
            $recharge = CarotRecharge::where('id', $id)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$this->canUpdateToStatus($recharge, CarotRecharge::STATUS_FAILED)) {
                return [
                    'success' => false,
                    'recharge' => $recharge,
                ];
            }

            $oldStatus = $recharge->status;
            $this->updateStatus($recharge, CarotRecharge::STATUS_FAILED, $request);

            if ($oldStatus === CarotRecharge::STATUS_PENDING) {
                $this->refundUser($recharge);
            }

            return [
                'success' => true,
                'recharge' => $recharge->fresh(),
            ];
        });

        if (!$result['success']) {
            return $this->invalidStatusResponse($result['recharge']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cap nhat don nap carot that bai',
            'data' => (new CarotRechargeResource($result['recharge']))->resolve(),
        ]);
    }

    private function canUpdateToStatus(CarotRecharge $recharge, string $newStatus): bool
    {
        return in_array($recharge->status, [CarotRecharge::STATUS_PENDING, $newStatus], true);
    }

    private function updateStatus(
        CarotRecharge $recharge,
        string $newStatus,
        UpdateCarotRechargeRequest $request
    ): void {
        $oldStatus = $recharge->status;

        if ($oldStatus === $newStatus) {
            $recharge->update($this->statusPayload($newStatus, $request, false));
            return;
        }

        $recharge->update($this->statusPayload($newStatus, $request));

        $this->adjustStatistics($recharge, $oldStatus, $newStatus);
    }

    private function statusPayload(
        string $status,
        UpdateCarotRechargeRequest $request,
        bool $includeStatus = true
    ): array {
        $payload = [
            'message' => $request->message ?? $this->defaultMessage($status),
            'api_response' => $request->api_response,
            'processed_at' => now(),
        ];

        if ($includeStatus) {
            $payload['status'] = $status;
        }

        if ($status === CarotRecharge::STATUS_SUCCESS && $request->filled('transaction_code')) {
            $payload['transaction_code'] = $request->transaction_code;
        }

        return $payload;
    }

    private function refundUser(CarotRecharge $recharge): void
    {
        $user = User::where('id', $recharge->user_id)
            ->lockForUpdate()
            ->firstOrFail();

        $oldBalance = $user->balance;
        $newBalance = $oldBalance + $recharge->amount;

        $user->update([
            'balance' => $newBalance,
        ]);

        TransactionService::log(
            userId: $user->id,
            type: 'carot_recharge_refund',
            amount: $recharge->amount,
            description: 'Hoan tien don nap carot that bai #' . $recharge->id,
            performedBy: null,
            related: $recharge,
            relatedId: $recharge->id,
            oldBalance: $oldBalance,
            newBalance: $newBalance,
            idempotencyKey: "carot-recharge-refund:{$recharge->id}",
            metadata: [
                'source' => 'app',
                'status' => $recharge->status,
                'transaction_code' => $recharge->transaction_code,
            ],
        );
    }

    private function adjustStatistics(CarotRecharge $recharge, string $oldStatus, string $newStatus): void
    {
        foreach ($this->statPeriods($recharge) as $type => $statDate) {
            foreach ([null, $recharge->user_id] as $userId) {
                foreach ([null, $recharge->server_id] as $serverId) {
                    $stat = CarotRechargeStatistic::firstOrCreate([
                        'type' => $type,
                        'stat_date' => $statDate,
                        'user_id' => $userId,
                        'server_id' => $serverId,
                    ]);

                    if ($oldStatus === CarotRecharge::STATUS_SUCCESS) {
                        $stat->decrement('success_transactions');
                        $stat->decrement('total_amount', $recharge->amount);
                        $stat->decrement('total_carot', $recharge->carot);
                    }

                    if ($oldStatus === CarotRecharge::STATUS_FAILED) {
                        $stat->decrement('failed_transactions');
                    }

                    if ($newStatus === CarotRecharge::STATUS_SUCCESS) {
                        $stat->increment('success_transactions');
                        $stat->increment('total_amount', $recharge->amount);
                        $stat->increment('total_carot', $recharge->carot);
                    }

                    if ($newStatus === CarotRecharge::STATUS_FAILED) {
                        $stat->increment('failed_transactions');
                    }
                }
            }
        }
    }

    private function statPeriods(CarotRecharge $recharge): array
    {
        $date = $recharge->created_at;

        return [
            CarotRechargeStatistic::TYPE_DAILY => $date->copy()->startOfDay()->toDateString(),
            CarotRechargeStatistic::TYPE_MONTHLY => $date->copy()->startOfMonth()->toDateString(),
            CarotRechargeStatistic::TYPE_YEARLY => $date->copy()->startOfYear()->toDateString(),
        ];
    }

    private function isAuthorizedApp(Request $request): bool
    {
        $appKey = config('services.carot_app_key');

        return $appKey && hash_equals($appKey, (string) $request->header('X-App-Key'));
    }

    private function unauthorizedResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized app',
        ], 401);
    }

    private function invalidStatusResponse(CarotRecharge $recharge): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Trang thai don nap carot khong cho phep cap nhat',
            'data' => (new CarotRechargeResource($recharge))->resolve(),
        ], 409);
    }

    private function defaultMessage(string $status): string
    {
        return match ($status) {
            CarotRecharge::STATUS_SUCCESS => 'App da nap carot thanh cong',
            CarotRecharge::STATUS_FAILED => 'App nap carot that bai',
            default => 'App da cap nhat don nap carot',
        };
    }
}
