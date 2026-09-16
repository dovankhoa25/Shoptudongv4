<?php
namespace App\Http\Middleware;

use App\Services\TrafficMonitorState;
use Closure;
use Illuminate\Http\Request;

final class StartTrafficTiming
{
    public function __construct(private TrafficMonitorState $state) {}

    public function handle(Request $request, Closure $next)
    {
        $enabled = $this->state->enabled();
        $request->attributes->set('_traffic_enabled', $enabled);
        if ($enabled) $request->attributes->set('_traffic_started', hrtime(true));
        return $next($request);
    }
}
