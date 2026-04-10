<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    | Laravel's own connection (used for framework internals, if any).
    */
    'default' => env('DB_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    */
    'connections' => [

        // ── Primary application database (PostgreSQL / TimescaleDB) ──────────
        'pgsql' => [
            'driver'         => 'pgsql',
            'url'            => env('DB_URL'),
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '5432'),
            'database'       => env('DB_DATABASE', 'mq_monitoring'),
            'username'       => env('DB_USERNAME', 'mq_user'),
            'password'       => env('DB_PASSWORD', ''),
            'charset'        => env('DB_CHARSET', 'utf8'),
            'prefix'         => '',
            'prefix_indexes' => true,
            'search_path'    => 'public',
            'sslmode'        => env('DB_SSLMODE', 'prefer'),
        ],

        // Laravel's own SQLite database (framework sessions, cache, etc.)
        'sqlite' => [
            'driver'                  => 'sqlite',
            'url'                     => env('DB_URL'),
            'database'                => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix'                  => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout'            => null,
            'journal_mode'            => null,
            'synchronous'             => null,
        ],

        /*
        |----------------------------------------------------------------------
        | Analytics views — PostgreSQL connection (analytics schema)
        |----------------------------------------------------------------------
        | Read-only access to the five TimescaleDB continuous-aggregate views
        | and materialized views created in the analytics schema:
        |   analytics.surveillance_hourly_activity
        |   analytics.daily_inactivity_per_employee
        |   analytics.daily_activity_totals_by_employee
        |   analytics.daily_alerts_by_type
        |   analytics.daily_alerts_by_employee
        |
        | search_path = analytics,public  so that bare table references resolve
        | to the analytics schema first, then fall back to public.
        */
        'analytics' => [
            'driver'      => 'pgsql',
            'url'         => env('DB_URL'),
            'host'        => env('DB_HOST', '127.0.0.1'),
            'port'        => env('DB_PORT', '5432'),
            'database'    => env('DB_DATABASE', 'mq_monitoring'),
            'username'    => env('DB_USERNAME', 'mq_user'),
            'password'    => env('DB_PASSWORD', ''),
            'charset'     => env('DB_CHARSET', 'utf8'),
            'prefix'      => '',
            'search_path' => 'analytics,public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        /*
        |----------------------------------------------------------------------
        | Surveillance analytics — PostgreSQL connection (surveillance schema)
        |----------------------------------------------------------------------
        | Reads the surveillance_events table written by the Python
        | surveillance runtime (surveillance/repositories/surveillance_repository.py).
        |
        | search_path = surveillance,public  so that bare table references resolve
        | to the surveillance schema first, then fall back to public.
        */
        'surveillance' => [
            'driver'      => 'pgsql',
            'url'         => env('DB_URL'),
            'host'        => env('DB_HOST', '127.0.0.1'),
            'port'        => env('DB_PORT', '5432'),
            'database'    => env('DB_DATABASE', 'mq_monitoring'),
            'username'    => env('DB_USERNAME', 'mq_user'),
            'password'    => env('DB_PASSWORD', ''),
            'charset'     => env('DB_CHARSET', 'utf8'),
            'prefix'      => '',
            'search_path' => 'surveillance,public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

        /*
        |----------------------------------------------------------------------
        | Attendance — PostgreSQL connection (attendance schema)
        |----------------------------------------------------------------------
        | Reads the attendance_events table written by the Python recognition
        | runtime (src/recognition/database.py) now that it targets PostgreSQL.
        |
        | search_path = attendance,public  so that bare table references resolve
        | to the attendance schema first, then fall back to public.
        */
        'attendance' => [
            'driver'      => 'pgsql',
            'url'         => env('DB_URL'),
            'host'        => env('DB_HOST', '127.0.0.1'),
            'port'        => env('DB_PORT', '5432'),
            'database'    => env('DB_DATABASE', 'mq_monitoring'),
            'username'    => env('DB_USERNAME', 'mq_user'),
            'password'    => env('DB_PASSWORD', ''),
            'charset'     => env('DB_CHARSET', 'utf8'),
            'prefix'      => '',
            'search_path' => 'attendance,public',
            'sslmode'     => env('DB_SSLMODE', 'prefer'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    */
    'migrations' => [
        'table'                => 'migrations',
        'update_date_on_publish' => true,
    ],

];
