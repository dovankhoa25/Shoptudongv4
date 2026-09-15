<?php
return [
    // Comma-separated HTTPS origins owned by this deployment; no client-supplied destinations.
    'origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('FRONTEND_CACHE_ORIGINS', ''))))),
    'secret' => env('FRONTEND_CACHE_WEBHOOK_SECRET'),
];
