<?php

namespace Tests\Support;

use Ably\AblyRest;
use App\Services\TradingV3\TradingActivityBroadcast;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Broadcasters\AblyBroadcaster;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Tests\TestCase;

/** Real Ably channel signatures with an application-local dummy key, never the operator's environment. */
abstract class AblyAuthorizationTestCase extends TestCase
{
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));

        // Routes register their channel callbacks while providers boot. Configure
        // the broadcaster before that point; dotenv can overwrite putenv values
        // it loaded during an earlier test application in the same PHP process.
        $app->afterBootstrapping(LoadConfiguration::class, static function (Application $app): void {
            $app['config']->set([
                'broadcasting.default' => 'ably',
                'broadcasting.connections.ably.key' => 'test.key:secret',
                'broadcasting.connections.ably.public_key' => 'test.key',
            ]);
        });

        $app->beforeBootstrapping(BootProviders::class, static function (Application $app): void {
            // Signing stays real. Fixture/model events may publish while testing
            // authorization, but their HTTP transport must remain in memory.
            $client = new class(['key' => 'test.key:secret', 'useBinaryProtocol' => false]) extends AblyRest {
                public array $requests = [];

                public function requestInternal($method, $path, $headers = [], $params = [], $returnHeaders = false, $auth = true)
                {
                    $this->requests[] = ['method' => $method, 'path' => $path];
                    return [];
                }
            };
            $app->make(BroadcastManager::class)->extend('ably', static fn () => new AblyBroadcaster($client));
            $app->bind(TradingActivityBroadcast::class, static fn () => new class($client) extends TradingActivityBroadcast {
                public function __construct(private AblyRest $testClient) {}
                protected function client(AblyBroadcaster $broadcaster): AblyRest { return $this->testClient; }
            });
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
