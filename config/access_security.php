<?php

return [
    // Initial default only; the admin switch in settings takes precedence once saved.
    'admin_approval_required' => (bool) env('ADMIN_ACCESS_APPROVAL_REQUIRED', true),
    'device_cookie' => 'admin_access_device',
    'device_days' => 90,
    // Inherit CACHE_STORE (Redis in production); can override without moving other caches.
    'ip_block_cache_store' => env('ACCESS_IP_BLOCK_CACHE_STORE'),
    'ip_block_cache_ttl' => (int) env('ACCESS_IP_BLOCK_CACHE_TTL', 60),
    // Only explicitly trusted reverse proxies may supply X-Forwarded-For.
    // Never use "*" or trust forwarded headers from arbitrary internet clients.
    'trusted_proxies' => array_values(array_filter(array_map('trim', explode(',', (string) env('SECURITY_TRUSTED_PROXIES', ''))))),
    // Optional shared secret for authenticated forwarding from the storefront BFF.
    'proxy_secret' => env('SECURITY_PROXY_SECRET'),
];
