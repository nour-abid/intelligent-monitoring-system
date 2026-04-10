<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Broadcast Connection
    |--------------------------------------------------------------------------
    | Drives realtime alerts. Set BROADCAST_CONNECTION=reverb in .env.
    | Reverb is the self-hosted, Pusher-compatible WebSocket server shipped
    | with Laravel (install: composer require laravel/reverb).
    */
    'default' => env('BROADCAST_CONNECTION', 'log'),

    'connections' => [

        'reverb' => [
            'driver'  => 'reverb',
            'key'     => env('REVERB_APP_KEY'),
            'secret'  => env('REVERB_APP_SECRET'),
            'app_id'  => env('REVERB_APP_ID'),
            'options' => [
                'host'   => env('REVERB_HOST', '0.0.0.0'),
                'port'   => env('REVERB_PORT', 6001),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'client_options' => [],
        ],

        'log'  => ['driver' => 'log'],
        'null' => ['driver' => 'null'],
    ],

];
