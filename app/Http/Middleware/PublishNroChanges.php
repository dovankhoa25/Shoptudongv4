<?php

namespace App\Http\Middleware;

use App\Events\NroShopUpdated;
use App\Support\ApiCache;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Publish once per successful NRO command, after its database transaction. */
class PublishNroChanges
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->isMethodSafe() || !$response->isSuccessful()) {
            return $response;
        }

        $action = $request->route()?->getActionMethod();
        // Worker heartbeats renew a lease; they are not website data updates.
        if ($action === 'heartbeat' && !$request->attributes->get('nro_delivery_message_changed')) {
            return $response;
        }

        $body = $response instanceof \Illuminate\Http\JsonResponse ? $response->getData(true) : [];
        $accounts = (array) $request->attributes->get('nro_changed_accounts', []);
        if ($action === 'claim') {
            if (!empty($body['data']['account']['id'])) {
                $accounts[] = (int) $body['data']['account']['id'];
            }
            if ($accounts === []) {
                return $response;
            }
        }

        try {
            $orderIds = [];
            $id = (int) $request->route('id');
            if ($request->is('app/nro-worker/jobs/*') || ($request->is('admin/nro-shop/jobs/*') && $id)) {
                $job = DB::table('nro_worker_jobs')->where('id', $id)->first(['account_id', 'order_id']);
                if ($job) {
                    $accounts[] = (int) $job->account_id;
                    if ($job->order_id) $orderIds[] = (int) $job->order_id;
                }
            } elseif ($request->is('*/nro-shop/accounts/*', 'app/nro-worker/accounts/*')) {
                $accounts[] = $id;
            } elseif ($request->is('api/nro-shop/orders*')) {
                $orderId = $id ?: (int) ($body['data']['id'] ?? 0);
                if ($orderId) {
                    $orderIds[] = $orderId;
                    $accounts[] = (int) DB::table('item_orders')->where('id', $orderId)->value('account_id');
                }
            }

            $accounts = array_values(array_unique(array_filter($accounts)));
            $catalog = !in_array($action, ['ready', 'tradePhase', 'beginRound', 'warehouseState', 'receive', 'heartbeat'], true);
            $buyers = ($accounts !== [] || $orderIds !== [])
                ? DB::table('item_orders')->where(function ($query) use ($accounts, $orderIds): void {
                    $query->whereIn('id', $orderIds)->orWhere(function ($active) use ($accounts): void {
                        $active->whereIn('account_id', $accounts)->whereNotIn('status', ['completed', 'refunded']);
                    });
                })->distinct()->pluck('buyer_id')->map(fn ($id) => (int) $id)->all()
                : [];

            DB::afterCommit(function () use ($catalog, $buyers): void {
                if ($catalog) ApiCache::clearGroup('public:nro-shop:listings');
                broadcast(new NroShopUpdated((string) Str::uuid(), $catalog, $buyers));
            });
        } catch (Throwable $exception) {
            Log::warning('NRO realtime publish failed', ['error' => $exception->getMessage()]);
        }

        return $response;
    }
}
