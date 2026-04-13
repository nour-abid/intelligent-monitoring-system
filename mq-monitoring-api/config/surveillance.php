<?php

/**
 * Surveillance Analytics Configuration
 *
 * Defines the database connection, schema, and table used by the
 * SurveillanceAnalyticsService to query the surveillance_events table
 * written by the Python surveillance runtime.
 *
 * All settings are configurable via environment variables for flexibility
 * across development, testing, and production environments.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    | The named database connection to use for surveillance analytics queries.
    |
    | If the configured connection does not exist in config/database.php,
    | the service will fall back to the default database connection.
    |
    | Default: 'surveillance' (defined in config/database.php)
    */
    'connection' => env('SURVEILLANCE_DB_CONNECTION', 'surveillance'),

    /*
    |--------------------------------------------------------------------------
    | Database Schema
    |--------------------------------------------------------------------------
    | The schema name where surveillance_events table is stored.
    |
    | In PostgreSQL, this is prefixed to the table name as 'schema.table'.
    | If empty or 'public', the table is queried without schema prefix.
    |
    | Default: 'surveillance'
    */
    'schema' => env('SURVEILLANCE_DB_SCHEMA', 'surveillance'),

    /*
    |--------------------------------------------------------------------------
    | Table Name
    |--------------------------------------------------------------------------
    | The table name containing surveillance events.
    |
    | This is the unqualified name; it will be combined with the schema
    | setting to build the fully-qualified name (e.g., 'surveillance.surveillance_events').
    |
    | Default: 'surveillance_events'
    */
    'table' => env('SURVEILLANCE_DB_TABLE', 'surveillance_events'),

    /*
    |--------------------------------------------------------------------------
    | Connection Verification
    |--------------------------------------------------------------------------
    | If true, the service will verify the configured connection and table
    | exist before executing queries. Failures raise a RuntimeException
    | with a precise diagnostic message.
    |
    | In production, this should remain true to catch configuration errors early.
    |
    | Default: true
    */
    'verify_connection' => (bool) env('SURVEILLANCE_VERIFY_CONNECTION', true),

];
