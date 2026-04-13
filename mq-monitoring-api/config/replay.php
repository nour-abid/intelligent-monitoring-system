<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Replay Clip Buffers
    |--------------------------------------------------------------------------
    |
    | pre_buffer_sec  — seconds before the alert event_time to include in the
    |                   generated clip.  Default: 300 (5 minutes).
    |
    | post_buffer_sec — seconds after the alert event_time to include.
    |                   Default: 60 (1 minute).
    |
    | These values are used as defaults when registering a new replay source
    | via POST /api/monitoring/surveillance/alerts/{id}/replay/source.
    | Each AlertReplaySource row can override them individually.
    |
    */
    'pre_buffer_sec'  => (int) env('REPLAY_PRE_BUFFER_SEC',  300),
    'post_buffer_sec' => (int) env('REPLAY_POST_BUFFER_SEC',  60),

    /*
    |--------------------------------------------------------------------------
    | Clip Cache TTL
    |--------------------------------------------------------------------------
    |
    | Generated clips are stored temporarily in the clips directory below.
    | cache_ttl_hours controls how long a clip is considered fresh before
    | ReplayService regenerates it on the next request.
    |
    | Default: 24 hours.  Set to 0 to always regenerate (useful in dev).
    |
    */
    'cache_ttl_hours' => (int) env('REPLAY_CACHE_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Clips Storage Directory
    |--------------------------------------------------------------------------
    |
    | Generated clips are written to storage/app/{clips_path}/.
    | The directory is created automatically if it does not exist.
    |
    | These files are temporary — clean them up with:
    |   php artisan replay:prune   (Artisan command, future implementation)
    | or via a scheduled task / OS cron.
    |
    */
    'clips_path' => env('REPLAY_CLIPS_PATH', 'replay_cache'),

    /*
    |--------------------------------------------------------------------------
    | FFmpeg Binary
    |--------------------------------------------------------------------------
    |
    | Path to the ffmpeg executable.  Defaults to 'ffmpeg' (assumes it is on
    | the system PATH).  Override with an absolute path if needed:
    |
    |   FFMPEG_PATH=/usr/bin/ffmpeg
    |
    */
    'ffmpeg_binary' => env('FFMPEG_PATH', 'ffmpeg'),

    /*
    |--------------------------------------------------------------------------
    | Internal Surveillance Token
    |--------------------------------------------------------------------------
    |
    | A shared secret that the Python surveillance pipeline sends as the
    | X-Internal-Token request header when POSTing clip metadata.
    |
    | Set SURVEILLANCE_INTERNAL_TOKEN in .env to any long random string.
    | Must match the clip_api_token value in surveillance/config/settings.yaml.
    |
    | Example (generate with):  php artisan key:generate --show | head -c 64
    |
    */
    'internal_token' => env('SURVEILLANCE_INTERNAL_TOKEN', ''),

];
