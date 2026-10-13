<?php

namespace App\Http\Controllers\AppAuto\VersionThree;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppAuto\VersionThree\HeartbeatRequest;
use App\Services\TradingV3\BatchHeartbeatService;

class HeartbeatController extends Controller
{
    public function __invoke(HeartbeatRequest $request, BatchHeartbeatService $service)
    {
        return response()->json(['success' => true, ...$service->renew($request->validated())]);
    }
}
