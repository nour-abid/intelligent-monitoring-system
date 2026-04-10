<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The following paths are served with CORS headers.  The wildcard '*' for
    | allowed_origins, methods, and headers is intentional for local dev;
    | restrict these in a production deployment.
    |
    | 'broadcasting/auth' must be included so the Angular client (running on a
    | different port) can POST to the Laravel Reverb channel-auth endpoint.
    | Without this, Echo's private-channel subscription silently fails and no
    | realtime alerts are delivered to the frontend.
    |
    */

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'broadcasting/auth',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
