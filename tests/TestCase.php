<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private string $trafficStatePath;

    protected function setUp(): void
    {
        parent::setUp();
        // Never read or change the operator's saved traffic switch during tests.
        $this->trafficStatePath = sys_get_temp_dir().'/traffic-monitor-test-'.bin2hex(random_bytes(12));
        config(['traffic_monitor.state_path' => $this->trafficStatePath]);
    }

    protected function tearDown(): void
    {
        if (isset($this->trafficStatePath) && is_file($this->trafficStatePath)) {
            unlink($this->trafficStatePath);
        }
        parent::tearDown();
    }
}
