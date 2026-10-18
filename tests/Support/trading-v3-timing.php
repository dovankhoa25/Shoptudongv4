<?php
// CLI fixture utilities. They reject every non-isolated database.
if (($argv[1] ?? 'seed') === 'seed') { require __DIR__.'/trading-v3-runtime.php'; exit; }
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.sqlite.database');
$root = realpath(dirname($db));
$tempRoot = realpath(sys_get_temp_dir());
if (!$app->environment('testing') || config('database.default') !== 'sqlite'
    || !is_string($root) || !str_starts_with(basename($root), 'trading-v3-runtime-db-')
    || !is_string($tempRoot) || strcasecmp(dirname($root), $tempRoot) !== 0) {
    throw new RuntimeException('Refusing non-isolated database.');
}
if (($argv[1] ?? '') === 'restore') {
    if (getenv('NRO_TRADING_V3_TIMING_RESUME') !== 'true' || getenv('NRO_TRADING_V3_TIMING_METHODS') !== 'concurrent_receipt') throw new RuntimeException('Explicit concurrent resume required.');
    $orders = App\Models\GemTransaction::orderBy('id')->get();
    $gift = $orders->where('delivery_method', 'gift_ticket');
    $consign = $orders->where('delivery_method', 'consignment');
    if ($orders->count() !== 3 || $gift->count() !== 1 || $consign->count() !== 2 || (int) $orders->sum('gem_qty') !== 70
        || $gift->first()->status !== 'completed' || (int) $gift->first()->gem_qty !== 10 || (int) $gift->first()->bot_gem_spent_qty !== 10 || (int) $gift->first()->tickets_used_qty !== 1
        || $consign->contains(fn ($order) => (int) $order->gem_qty !== 30 || (int) $order->bot_gem_spent_qty !== 0 || !in_array($order->status, ['pending', 'processing'], true))) {
        throw new RuntimeException('Resume requires the original completed gift and two unspent consignment orders.');
    }
    $bot = App\Models\GemBot::sole();
    if ((int) $bot->server_game_id !== 6 || (int) $bot->map_id !== 84 || (int) $bot->area_number !== 41 || $bot->coordinates !== '652-336') throw new RuntimeException('Original SV6 point required.');
    $user = getenv('TRADING_TEST_BOT_USERNAME'); $password = getenv('TRADING_TEST_BOT_PASSWORD');
    if (!$user || !$password) throw new RuntimeException('Ephemeral original bot credentials required.');
    Illuminate\Support\Facades\DB::table('gem_bots')->where('id', $bot->id)->update(['account_name' => $user, 'account_password' => $password]);
    echo "Restored original isolated SV6 credentials; all three orders, ledgers and stock versions retained.\n"; exit;
}
if (($argv[1] ?? '') !== 'sanitize') throw new RuntimeException('Unknown fixture operation.');
$redactions = json_decode((string) getenv('TRADING_TEST_REDACTIONS_JSON'), true);
if (!is_array($redactions) || !$redactions) throw new RuntimeException('Missing explicit redaction values.');
$pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
$quote = fn ($name) => '"'.str_replace('"', '""', $name).'"';
$pdo->beginTransaction();
try {
    foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
        foreach ($pdo->query('PRAGMA table_info('.$quote($table).')')->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (!preg_match('/CHAR|TEXT|CLOB/i', $column['type'])) continue;
            $name = $quote($column['name']);
            $statement = $pdo->prepare('UPDATE '.$quote($table).' SET '.$name.'=replace('.$name.',?,?) WHERE instr('.$name.',?)>0');
            foreach ($redactions as $secret) {
                if (is_string($secret) && $secret !== '') $statement->execute([$secret, '[hidden]', $secret]);
            }
        }
    }
    $pdo->exec("UPDATE gem_bots SET account_name='test-bot-redacted',account_password=''");
    $pdo->commit();
} catch (Throwable $exception) { $pdo->rollBack(); throw $exception; }
$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$pdo->exec('VACUUM');
$bytes = file_get_contents($db);
foreach ($redactions as $secret) {
    if (is_string($secret) && $secret !== '' && str_contains($bytes, $secret)) {
        throw new RuntimeException('Sanitized database still contains a credential; refusing evidence copy.');
    }
}
echo "Sanitized isolated database, including text history and freed pages.\n";
