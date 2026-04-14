<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root'   => storage_path('app/private'),
            'serve'  => true,
            'throw'  => false,
        ],

        'public' => [
            'driver'     => 'local',
            'root'       => storage_path('app/public'),
            'url'        => env('APP_URL') . '/storage',
            'visibility' => 'public',
            'throw'      => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Identity Enrollment Photos
        |----------------------------------------------------------------------
        |
        | Private disk for facial enrollment images uploaded by admins.
        | Files are served exclusively through the API (/api/photos/{id}/image)
        | so they never become publicly accessible via the web root.
        |
        | Directory structure on disk:
        |   storage/app/private/identity_photos/{userId}/{uuid}.{ext}
        |
        */
        'identity_photos' => [
            'driver' => 'local',
            'root'   => storage_path('app/private/identity_photos'),
            'throw'  => true,   // Surface storage errors immediately during upload
        ],

        's3' => [
            'driver'                  => 's3',
            'key'                     => env('AWS_ACCESS_KEY_ID'),
            'secret'                  => env('AWS_SECRET_ACCESS_KEY'),
            'region'                  => env('AWS_DEFAULT_REGION'),
            'bucket'                  => env('AWS_BUCKET'),
            'url'                     => env('AWS_URL'),
            'endpoint'                => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw'                   => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | The `public` disk is NOT listed here because identity_photos is private
    | and routed through the API, not symlinked.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
