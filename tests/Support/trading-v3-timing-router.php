<?php
// Loopback-only timing fixture; this file is not registered in production routes.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$request = Illuminate\Http\Request::capture();
$app->instance('request', $request);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$db = (string) config('database.connections.sqlite.database');
$dbRoot = realpath(dirname($db));
$tempRoot = realpath(sys_get_temp_dir());
if (!$app->environment('testing') || config('database.default') !== 'sqlite'
    || !is_string($dbRoot) || !str_starts_with(basename($dbRoot), 'trading-v3-runtime-db-')
    || !is_string($tempRoot) || strcasecmp(dirname($dbRoot), $tempRoot) !== 0
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403); echo 'Isolated test backend required.'; return;
}
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$methods = (string) (getenv('NRO_TRADING_V3_TIMING_METHODS') ?: 'both');
if (!in_array($methods, ['both', 'gift_ticket', 'gift_shop_interleave'], true)) { http_response_code(403); return; }
$giftOnly = $methods !== 'both';
$allowedMethods = $giftOnly ? ['gift_ticket'] : ['consignment', 'gift_ticket'];
$maximumGems = $giftOnly ? 10 : 40;
$body = json_decode(file_get_contents('php://input'), true) ?? [];
if (!is_array($body)) $body = [];
$started = hrtime(true);
$stamp = fn () => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
$record = function (int $status) use ($dbRoot, $path, $body, $started, $stamp): void {
    // No headers, credentials or raw payloads enter the report.
    $row = ['at_utc' => $stamp(), 'method' => $_SERVER['REQUEST_METHOD'], 'path' => $path,
        'status' => $status, 'duration_ms' => round((hrtime(true) - $started) / 1e6, 3)];
    if (preg_match('#^/app/v3/gem-transactions/(\d+)$#', $path, $match)) {
        $row['order_id'] = (int) $match[1];
        foreach (['action', 'phase', 'completed'] as $key) {
            if (isset($body[$key]) && is_scalar($body[$key])) $row[$key] = $body[$key];
        }
    }
    file_put_contents($dbRoot.'/http-timeline.jsonl', json_encode($row, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
};

if (str_starts_with($path, '/testing/')) {
    $key = (string) getenv('NRO_TRADING_V3_HMAC_SECRET');
    if ($key === '' || !hash_equals($key, $_SERVER['HTTP_X_TEST_KEY'] ?? '')) {
        http_response_code(403); return;
    }
    header('Content-Type: application/json');
    if ($path === '/testing/order' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $method = $body['method'] ?? '';
        $name = $body['name'] ?? '';
        if (!is_string($method) || !is_string($name)
            || !in_array($method, $allowedMethods, true)
            || !preg_match('/^[a-zA-Z0-9_-]{3,30}$/D', $name)) {
            http_response_code(422); $record(422);
            echo json_encode(['success' => false, 'message' => $giftOnly
                ? 'This fixture permits only one 10-gem ticket order; consignment was already tested.'
                : 'Only a 30-gem consignment and a 10-gem ticket order are allowed.']); return;
        }
        $result = Illuminate\Support\Facades\DB::transaction(function () use ($method, $name, $allowedMethods, $maximumGems) {
            $orders = App\Models\GemTransaction::query()->lockForUpdate()->get();
            if ($orders->count() >= count($allowedMethods) || $orders->contains('delivery_method', $method)) return null;
            $quantity = $method === 'gift_ticket' ? 10 : 30;
            if ($orders->sum('gem_qty') + $quantity > $maximumGems) return null;
            $bot = App\Models\GemBot::sole();
            if ((int) $bot->server_game_id !== 6 || (int) $bot->map_id !== 84
                || (int) $bot->area_number !== 41 || $bot->coordinates !== '652-336') {
                throw new RuntimeException('Fixture must remain at the authorized SV6 location.');
            }
            $user = App\Models\User::firstOrCreate(['email' => 'v3-timing-test@example.invalid'],
                ['username' => 'v3timingtester', 'password' => Illuminate\Support\Facades\Hash::make(Illuminate\Support\Str::random(32))]);
            return App\Models\GemTransaction::create(['user_id' => $user->id, 'bot_id' => $bot->id,
                'server_id' => $bot->server_id, 'character_name' => $name, 'gem_qty' => $quantity,
                'amount_vnd' => 10000, 'price_at_transaction' => $quantity,
                'delivery_method' => $method, 'status' => 'pending', 'updated_by' => 'web']);
        });
        if (!$result) {
            http_response_code(409); $record(409);
            echo json_encode(['success' => false, 'message' => 'The bounded order for this method already exists; do not replay.']); return;
        }
        $record(200);
        echo json_encode(['success' => true, 'created_at_utc' => $stamp(),
            'data' => (new App\Http\Resources\AppAuto\VersionThree\GemOrderResource($result))->resolve()]); return;
    }
    if ($path === '/testing/report' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $timeline = [];
        if (is_file($dbRoot.'/http-timeline.jsonl')) {
            foreach (file($dbRoot.'/http-timeline.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $entry = json_decode($line, true); if (is_array($entry)) $timeline[] = $entry;
            }
        }
        $orders = App\Models\GemTransaction::orderBy('id')->get();
        $movements = App\Models\InventoryMovement::whereNotNull('transaction_id')
            ->get(['transaction_id', 'asset_type', 'quantity_delta', 'idempotency_key', 'meta']);
        $ledgerProof = $orders->map(function ($order) use ($movements) {
            $financial = $movements->where('transaction_id', $order->id)->where('asset_type', 'gem')
                ->filter(fn ($movement) => $movement->quantity_delta < 0);
            $tickets = $movements->where('transaction_id', $order->id)->where('asset_type', 'gem_ticket')
                ->filter(fn ($movement) => $movement->quantity_delta < 0);
            $gemDelta = -(int) $financial->sum('quantity_delta');
            $ticketDelta = -(int) $tickets->sum('quantity_delta');
            $expectedTickets = $order->delivery_method === 'gift_ticket' ? 1 : 0;
            return ['order_id' => $order->id, 'financial_receipt_count' => $financial->count(),
                'acknowledged_gems' => $gemDelta, 'ticket_receipt_count' => $tickets->count(),
                'acknowledged_tickets' => $ticketDelta, 'completed_once' => $order->status === 'completed'
                    && $financial->count() === 1 && $gemDelta === (int) $order->gem_qty
                    && $ticketDelta === $expectedTickets && (int) $order->tickets_used_qty === $expectedTickets];
        });
        echo json_encode(['success' => true, 'at_utc' => $stamp(), 'data' => [
            'orders' => $orders->map(
                fn ($order) => (new App\Http\Resources\AppAuto\VersionThree\GemOrderResource($order))->resolve()),
            'movements' => $movements, 'ledger_proof' => $ledgerProof,
            'bounds' => ['methods' => $methods, 'consignment_gems' => $giftOnly ? 0 : 30,
                'ticket_gems' => 10, 'tickets' => 1, 'total_gems' => $maximumGems],
            'timeline' => $timeline,
        ]], JSON_UNESCAPED_UNICODE); return;
    }
    http_response_code(404); return;
}
if (!str_starts_with($path, '/app/v3/')) { http_response_code(404); return; }
$response = $kernel->handle($request);
$record($response->getStatusCode());
$response->send();
$kernel->terminate($request, $response);
