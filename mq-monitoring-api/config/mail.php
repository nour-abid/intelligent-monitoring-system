<?php

return [

    'default' => env('MAIL_MAILER', 'smtp'),

    'mailers' => [

        'smtp' => [
            // 'smtp-nossl' is a custom transport registered in AppServiceProvider
            // that disables SSL peer verification for mail.marqenti.tn.
            'transport'  => 'smtp-nossl',
            'host'       => env('MAIL_HOST', '127.0.0.1'),
            'port'       => env('MAIL_PORT', 465),
            'encryption' => env('MAIL_ENCRYPTION', 'ssl'),
            'username'   => env('MAIL_USERNAME'),
            'password'   => env('MAIL_PASSWORD'),
            'timeout'    => null,
        ],

        'log' => [
            'transport' => 'log',
            'channel'   => env('MAIL_LOG_CHANNEL', 'stack'),
        ],

        'array' => [
            'transport' => 'array',
        ],

    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name'    => env('MAIL_FROM_NAME', 'Example'),
    ],

];
