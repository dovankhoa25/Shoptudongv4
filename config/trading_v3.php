<?php
return [
    'enabled' => (bool) env('NRO_TRADING_V3_ENABLED', false),
    'tool_id' => env('NRO_TRADING_V3_TOOL_ID', 'nro-trading-v3'),
    'hmac_secret' => env('NRO_TRADING_V3_HMAC_SECRET', ''),
    'clock_skew_seconds' => 90,
    // Compatibility response fields only. Bot execution has no lease or renewal.
    'lease_seconds' => 0,
    'renewal_interval_seconds' => 0,
    'max_renewal_interval_seconds' => 3600,
    // Activity always uses an in-request termination callback; old env cannot enable queues.
    'activity_dispatch' => 'after_response',
    // Legacy queue location is used only by the one-off backlog cleanup command.
    'activity_queue_connection' => env('NRO_TRADING_V3_ACTIVITY_QUEUE_CONNECTION', 'redis'),
    'activity_queue' => env('NRO_TRADING_V3_ACTIVITY_QUEUE', 'trading-activity'),
    'login_warmup_seconds' => 120,
    'gift_interval_seconds' => 10,
    'activity_ttl_seconds' => 35,
];

