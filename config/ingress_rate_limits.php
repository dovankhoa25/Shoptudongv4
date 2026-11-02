<?php

return [
    // Shared, per resolved client IP, across paths in each family. These run
    // before route authentication; account/action limits still apply afterward.
    // Keep tool budgets separate from storefront and admin traffic.
    'per_minute' => [
        'auth' => (int) env('INGRESS_AUTH_PER_MINUTE', 120),
        'api-read' => (int) env('INGRESS_API_READ_PER_MINUTE', 240),
        'api-write' => (int) env('INGRESS_API_WRITE_PER_MINUTE', 120),
        'admin' => (int) env('INGRESS_ADMIN_PER_MINUTE', 600),
        'legacy-tool' => (int) env('INGRESS_LEGACY_TOOL_PER_MINUTE', 600),
        'trading-tool' => (int) env('INGRESS_TRADING_TOOL_PER_MINUTE', 3000),
        'worker' => (int) env('INGRESS_WORKER_PER_MINUTE', 600),
        'storage' => (int) env('INGRESS_STORAGE_PER_MINUTE', 600),
        'web' => (int) env('INGRESS_WEB_PER_MINUTE', 120),
    ],
];
