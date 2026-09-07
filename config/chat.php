<?php

return [
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
];
