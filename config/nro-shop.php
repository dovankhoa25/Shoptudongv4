<?php

return [
    // Destination for admin preview links; this does not assign listings to a website.
    'frontend_url' => env('NRO_SHOP_FRONTEND_URL', env('APP_ENV') === 'local' ? 'http://localhost:3000' : null),
];
