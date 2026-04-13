<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The following paths are served with CORS headers. For local development,
    | we allow all origins. In production, restrict to known frontend URLs.
    |
    | 'supports_credentials' => true allows sending cookies/auth headers
    | from the Angular client (running on a different port).
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

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-CSRF-TOKEN',
    ],

    'exposed_headers' => [
        'Authorization',
        'Content-Type',
    ],

    'max_age'               => 86400,

    'supports_credentials'  => true,

];
