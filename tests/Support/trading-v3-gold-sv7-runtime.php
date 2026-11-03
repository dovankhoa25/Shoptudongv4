<?php
// Isolated, two-account, one-bar round trip. Never load this fixture in a web deployment.
try {
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $db = (string)config('database.connections.sqlite.database');
    $temp = realpath(sys_get_temp_dir()); $resolved = realpath($db);
    if (!$app->environment('testing') || config('database.default') !== 'sqlite'
        || !$resolved || !$temp || !str_starts_with(strtolower($resolved), strtolower($temp.DIRECTORY_SEPARATOR))
        || !str_starts_with(basename(dirname($resolved)), 'trading-v3-gold-sv7-db-')
        || basename($resolved) !== 'test.sqlite' || getenv('NRO_GOLD_SV7_TEST') !== 'true') {
        throw new RuntimeException('Explicit isolated SV7 SQLite fixture required.');
    }
    if (($argv[1] ?? '') === 'sanitize') {
        Illuminate\Support\Facades\DB::statement('PRAGMA secure_delete=ON');
        Illuminate\Support\Facades\DB::table('bots')->update(['account_name' => 'sv7-test-redacted', 'account_password' => '', 'proxy' => null]);
        Illuminate\Support\Facades\DB::statement('VACUUM');
        echo "Sanitized SV7 fixture credentials.\n"; exit;
    }
    if (App\Models\Bot::exists() || App\Models\InventoryMovement::exists() || App\Models\GoldTransaction::exists()) {
        throw new RuntimeException('Fresh SV7 fixture required; never replay an existing round trip.');
    }
    $host = getenv('NRO_GOLD_SV7_HOST'); $port = filter_var(getenv('NRO_GOLD_SV7_PORT'), FILTER_VALIDATE_INT);
    if (!$host || !preg_match('/^[a-zA-Z0-9.-]+$/D', $host) || $port < 1 || $port > 65535) throw new RuntimeException('Explicit SV7 endpoint required.');
    $baseline = [];
    foreach (['MAIN', 'AUX'] as $role) {
        foreach (['BARS' => 999, 'NRJ_BARS' => 999, 'PURE_GOLD' => 2147483647] as $field => $max) {
            $value = filter_var(getenv('NRO_GOLD_SV7_'.$role.'_'.$field), FILTER_VALIDATE_INT);
            if ($value === false || $value < 0 || $value > $max) throw new RuntimeException('Explicit observed stock baseline required.');
            $baseline[strtolower($role.'_'.$field)] = $value;
        }
    }
    if ($baseline['main_bars'] !== 0 || $baseline['aux_bars'] < 1 || $baseline['aux_bars'] > 999) {
        throw new RuntimeException('This one-bar refill fixture requires an empty main and a stocked auxiliary.');
    }
    $server = App\Models\Server::create(['name' => 'SV7 bounded gold roundtrip', 'name_view' => '7 sao', 'status' => true]);
    Illuminate\Support\Facades\DB::table('server_game_login')->insert(['id' => 7, 'name' => 'SV7 isolated', 'ip' => $host, 'port' => (string)$port]);
    $accounts = [];
    foreach ([1 => ['MAIN', 'selling_main'], 3 => ['AUX', 'selling_sub']] as $id => [$role, $type]) {
        $user = getenv('NRO_GOLD_SV7_'.$role.'_USER'); $password = getenv('NRO_GOLD_SV7_'.$role.'_PASSWORD');
        if (!$user || !$password || in_array(mb_strtolower($user), $accounts, true)) throw new RuntimeException('Two distinct ephemeral test accounts required.');
        $accounts[] = mb_strtolower($user);
        $bot = new App\Models\Bot();
        $bot->forceFill(['id' => $id, 'name' => 'SV7 test '.$role, 'account_name' => $user, 'account_password' => $password,
            'type' => $type, 'server_id' => $server->id, 'server_game_id' => 7, 'status' => true,
            'gold_bar_qty' => $baseline[strtolower($role).'_bars'], 'nrj_gold_bar_qty' => $baseline[strtolower($role).'_nrj_bars'],
            'gold_qty' => $baseline[strtolower($role).'_pure_gold'],
            'map_name' => 'Siêu thị', 'map_id' => '84', 'area_number' => '41', 'coordinates' => '652-336'])->save();
    }
    file_put_contents(dirname($db).'/sv7-fixture.json', json_encode(['stage' => 'refill', 'baseline' => $baseline], JSON_THROW_ON_ERROR));
    echo "Created isolated SV7 two-bot fixture; budget one bar refill and one bar return; no orders.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'SV7 fixture stopped: '.$error->getMessage().PHP_EOL); exit(1);
}
