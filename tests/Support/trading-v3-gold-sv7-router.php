<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$request = Illuminate\Http\Request::capture(); $app->instance('request', $request);
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class); $kernel->bootstrap();
$db = (string)config('database.connections.sqlite.database'); $temp = realpath(sys_get_temp_dir()); $resolved = realpath($db);
if (!$app->environment('testing') || config('database.default') !== 'sqlite' || !$resolved || !$temp
    || !str_starts_with(strtolower($resolved), strtolower($temp.DIRECTORY_SEPARATOR))
    || !str_starts_with(basename(dirname($resolved)), 'trading-v3-gold-sv7-db-') || basename($resolved) !== 'test.sqlite'
    || getenv('NRO_GOLD_SV7_TEST') !== 'true' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403); return;
}
$fixturePath = dirname($db).'/sv7-fixture.json';
$fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); $body = json_decode(file_get_contents('php://input'), true) ?? [];
$moves = fn() => App\Models\InventoryMovement::where('movement_type', 'reservation')->where('transaction_type', 'gold_transfer')->orderBy('id')->get();
$json = function (array $data, int $status = 200): void { http_response_code($status); header('Content-Type: application/json'); echo json_encode($data, JSON_THROW_ON_ERROR); };
if (str_starts_with($path, '/testing-gold/')) {
    if (!hash_equals((string)getenv('NRO_TRADING_V3_HMAC_SECRET'), $_SERVER['HTTP_X_TEST_KEY'] ?? '')) { $json(['success' => false], 403); return; }
    if ($path === '/testing-gold/report' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $json(['success' => true, 'data' => ['stage' => $fixture['stage'], 'baseline' => $fixture['baseline'],
            'bots' => App\Models\Bot::orderBy('id')->get(['id', 'name', 'type', 'status', 'gold_qty', 'gold_bar_qty', 'nrj_gold_bar_qty', 'stock_version', 'map_id', 'area_number', 'coordinates']),
            'orders' => [], 'transfers' => $moves()->map(fn($row) => [...$row->meta, 'meta' => $row->meta]),
            'movements' => App\Models\InventoryMovement::get(['bot_id', 'transaction_type', 'movement_type', 'quantity_delta', 'idempotency_key'])]]); return;
    }
    if ($path === '/testing-gold/stage' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($body['stage'] ?? '') === 'store') {
        if ($fixture['stage'] === 'store') { $json(['success' => true, 'data' => ['stage' => 'store']]); return; }
        $ok = Illuminate\Support\Facades\DB::transaction(function () use ($moves, $fixture) {
            $transfers = $moves(); $first = $transfers->first()?->meta ?? [];
            $main = App\Models\Bot::whereKey(1)->lockForUpdate()->firstOrFail();
            $aux = App\Models\Bot::whereKey(3)->lockForUpdate()->firstOrFail();
            if ($transfers->count() !== 1 || ($first['status'] ?? '') !== 'completed'
                || ($first['from_bot_id'] ?? 0) !== 3 || ($first['to_bot_id'] ?? 0) !== 1
                || ($first['bars_qty'] ?? 0) !== 1 || ($first['pure_gold_qty'] ?? 0) !== 0 || ($first['gold_bar_template_id'] ?? 457) !== 457
                || App\Models\GoldTransaction::exists() || (int)$main->gold_bar_qty !== $fixture['baseline']['main_bars'] + 1
                || (int)$aux->gold_bar_qty !== $fixture['baseline']['aux_bars'] - 1
                || (int)$main->nrj_gold_bar_qty !== $fixture['baseline']['main_nrj_bars'] || (int)$aux->nrj_gold_bar_qty !== $fixture['baseline']['aux_nrj_bars']) return false;
            $main->update(['type' => 'import_main']); $aux->update(['type' => 'import_sub']); return true;
        });
        if (!$ok) { $json(['success' => false, 'message' => 'First exact one-bar refill and its receipt must be complete before return.'], 409); return; }
        $fixture['stage'] = 'store'; file_put_contents($fixturePath, json_encode($fixture, JSON_THROW_ON_ERROR));
        $json(['success' => true, 'data' => ['stage' => 'store']]); return;
    }
    $json(['success' => false], 404); return;
}
// Fixture budget is independent of tool scheduling: no more than two original one-bar plans.
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && preg_match('#^/app/v3/gold-transfers/([a-f0-9-]+)$#i', $path, $match)) {
    $transfers = $moves(); $existing = $transfers->first(fn($row) => ($row->meta['id'] ?? '') === $match[1]);
    $from = $fixture['stage'] === 'refill' ? 3 : 1; $to = $fixture['stage'] === 'refill' ? 1 : 3;
    $expected = $existing?->meta ?? ['from_bot_id' => $from, 'to_bot_id' => $to];
    $newPlan = ($body['action'] ?? '') === 'claim'
        || (($body['action'] ?? '') === 'receipt' && ($body['evidence']['intent']['local_transfer'] ?? false) === true);
    if ((int)($body['bars_qty'] ?? 0) !== 1 || (int)($body['pure_gold_qty'] ?? 0) !== 0 || (int)($body['gold_bar_template_id'] ?? 457) !== 457
        || (int)($body['from_bot_id'] ?? 0) !== $expected['from_bot_id'] || (int)($body['to_bot_id'] ?? 0) !== $expected['to_bot_id']
        || (!$existing && (!$newPlan || $transfers->count() !== ($fixture['stage'] === 'refill' ? 0 : 1)))) {
        $json(['success' => false, 'message' => 'Isolated SV7 budget permits one original one-bar transfer in each direction only.'], 409); return;
    }
}
if (preg_match('#^/app/v3/gold-transactions/\d+$#', $path) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    $json(['success' => false, 'message' => 'No customer orders in this bounded round trip.'], 403); return;
}
$response = $kernel->handle($request); $response->send(); $kernel->terminate($request, $response);
