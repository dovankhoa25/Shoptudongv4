<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Internal Password Grant Client
    |--------------------------------------------------------------------------
    |
    | This confidential Passport client is used only by this backend when a
    | trusted first-party frontend calls /api/auth/*. Never expose its secret
    | to browser or mobile clients.
    |
    */
    'password_client_id' => env('SSO_PASSWORD_CLIENT_ID'),
    'password_client_secret' => env('SSO_PASSWORD_CLIENT_SECRET'),

    'admin_user_ids' => array_values(array_filter(explode(',', env('SSO_ADMIN_USER_IDS', '')))),
];
