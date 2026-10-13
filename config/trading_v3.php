<?php
return [
    'enabled' => (bool) env('NRO_TRADING_V3_ENABLED', false),
    'tool_id' => env('NRO_TRADING_V3_TOOL_ID', 'nro-trading-v3'),
    'hmac_secret' => env('NRO_TRADING_V3_HMAC_SECRET', ''),
    'clock_skew_seconds' => 90,
    'lease_seconds' => 90,
    'renewal_interval_seconds' => (int) env('NRO_TRADING_V3_RENEWAL_INTERVAL_SECONDS', 150),
    'max_renewal_interval_seconds' => 3600,
    'lease_margin_seconds' => 60,
    'activity_queue_connection' => env('NRO_TRADING_V3_ACTIVITY_QUEUE_CONNECTION', 'redis'),
    'activity_queue' => env('NRO_TRADING_V3_ACTIVITY_QUEUE', 'trading-activity'),
    'activity_cache_store' => env('NRO_TRADING_V3_ACTIVITY_CACHE_STORE', 'redis'),
    'login_warmup_seconds' => 120,
    'gift_interval_seconds' => 10,
    'activity_ttl_seconds' => 35,
];

