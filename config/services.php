<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v23.0'),
    ],

    'sepay' => [
        'webhook_key' => env('SEPAY_WEBHOOK_API_KEY'),
        'transfer_prefix' => env('SEPAY_TRANSFER_PREFIX', 'shop'),
    ],

    // Dùng lại khóa của backend cũ để giải mã tài khoản nick đã lưu production.
    'account_key' => env('ACCOUNT_KEY'),

    'nextjs_webhook' => [
        'url' => env('NEXTJS_WEBHOOK_URL'),
        'secret' => env('WEBHOOK_SECRET'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
    ],

    'card_partner' => [
        'url' => env('CARD_PARTNER_URL', 'https://thesieure.com/chargingws/v2'),
        'id' => env('CARD_PARTNER_ID'),
        'key' => env('CARD_PARTNER_KEY'),
        'timeout' => (int) env('CARD_PARTNER_TIMEOUT', 30),
        'amounts' => [10000, 20000, 30000, 50000, 100000, 200000, 300000, 500000, 1000000],
    ],

    'app_api_key' => env('APP_API_KEY'),
    'carot_app_key' => env('CAROT_APP_KEY'),
];
