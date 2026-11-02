<?php

return [
    // Use a server-supplied connection address, never an HTTP forwarding header.
    // Hosts that rewrite REMOTE_ADDR must expose the original peer in a separate
    // FastCGI/server variable and configure its name here.
    'peer_server_key' => env('FINANCIAL_WEBHOOK_PEER_SERVER_KEY', 'REMOTE_ADDR'),

    // Owner-provided Cloudflare networks, checked against Cloudflare on 2026-11-02.
    // This restricts ingress to Cloudflare; provider credentials remain mandatory.
    'allowed_peer_networks' => [
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '108.162.192.0/18',
        '131.0.72.0/22',
        '141.101.64.0/18',
        '162.158.0.0/15',
        '172.64.0.0/13',
        '173.245.48.0/20',
        '188.114.96.0/20',
        '190.93.240.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ],
];
