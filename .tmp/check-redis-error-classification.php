<?php

// Local diagnostic only: simulated cache exceptions, no live Redis or application DB calls.
require __DIR__.'/../vendor/autoload.php';
if (! class_exists('RedisException')) {
    class RedisException extends RuntimeException {}
}
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$app['config']->set('cache.default', 'array');
$originalCache = Illuminate\Support\Facades\Cache::getFacadeRoot();
$originalLog = Illuminate\Support\Facades\Log::getFacadeRoot();

foreach (['Connection refused', 'No such file or directory'] as $message) {
    $calls = 0;
    $cache = Mockery::mock();
    $cache->shouldReceive('get')->once()->andThrow(new RedisException($message));
    Illuminate\Support\Facades\Cache::swap($cache);
    Illuminate\Support\Facades\Log::swap(Mockery::mock()->shouldIgnoreMissing());
    $app->instance('request', Illuminate\Http\Request::create('/local-cache-diagnostic'));
    try {
        $value = App\Support\ApiCache::remember('local-diagnostic', 'probe', 30, function () use (&$calls) {
            $calls++;
            return 'producer-result';
        });
        $outcome = ['outcome' => 'fallback_returned', 'value' => $value];
    } catch (Throwable $exception) {
        $outcome = ['outcome' => 'exception_propagated', 'exception' => get_class($exception)];
    }
    echo json_encode(['simulated_error' => $message, ...$outcome, 'producer_calls' => $calls]), PHP_EOL;
    Mockery::close();
}
Illuminate\Support\Facades\Cache::swap($originalCache);
Illuminate\Support\Facades\Log::swap($originalLog);

$connector = new class extends Illuminate\Redis\Connectors\PhpRedisConnector {
    public function connectionArguments(array $configuration): array
    {
        return [$this->formatHost($configuration), $configuration['port']];
    }
};
echo json_encode(['laravel_connection_arguments' => $connector->connectionArguments([
    'host' => '/home/account/.applicationmanager/redis.sock', 'port' => '0',
])]), PHP_EOL;
