<?php

return [
    'rate_limits' => [
        // IP ingress is shared across accounts; leave room for offices and mobile NAT.
        'ingress_per_minute' => (int) env('CHAT_INGRESS_PER_MINUTE', 600),
        'resolve_per_minute' => (int) env('CHAT_RESOLVE_PER_MINUTE', 1),
        'send_per_minute' => (int) env('CHAT_SEND_PER_MINUTE', 10),
        'agent_send_per_minute' => (int) env('CHAT_AGENT_SEND_PER_MINUTE', 90),
        'upload_per_minute' => (int) env('CHAT_UPLOAD_PER_MINUTE', 6),
    ],

    'welcome_message' => env(
        'CHAT_WELCOME_MESSAGE',
        'Hỗ trợ viên đã sẵn sàng. Bạn cần hỗ trợ gì không?',
    ),

    'attachments' => [
        'disk' => env('CHAT_MEDIA_DISK', 'chat'),
        'max_files' => (int) env('CHAT_MEDIA_MAX_FILES', 4),
        'max_kilobytes' => (int) env('CHAT_MEDIA_MAX_KILOBYTES', 5120),
        'signed_url_minutes' => (int) env('CHAT_MEDIA_SIGNED_URL_MINUTES', 60),
        'retention_days' => (int) env('CHAT_MEDIA_RETENTION_DAYS', 90),
    ],

    'reactions' => [
        'allowed' => ['👍', '❤️', '😂', '😮', '😢', '🙏', '🎉'],
    ],

    'tips' => [
        'min_amount' => (int) env('CHAT_TIP_MIN_AMOUNT', 1000),
        'max_amount' => (int) env('CHAT_TIP_MAX_AMOUNT', 1000000),
        'daily_limit' => (int) env('CHAT_TIP_DAILY_LIMIT', 2000000),
    ],
];
