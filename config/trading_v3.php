<?php
return [
    'enabled' => (bool) env('NRO_TRADING_V3_ENABLED', false),
    'tool_id' => env('NRO_TRADING_V3_TOOL_ID', 'nro-trading-v3'),
    'hmac_secret' => env('NRO_TRADING_V3_HMAC_SECRET', ''),
    'clock_skew_seconds' => 90,
    'lease_seconds' => 90,
    'login_warmup_seconds' => 120,
    'gift_interval_seconds' => 10,
    'activity_ttl_seconds' => 35,
];

