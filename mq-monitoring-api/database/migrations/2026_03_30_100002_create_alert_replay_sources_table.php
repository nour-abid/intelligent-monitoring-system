<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores the metadata linking a behavior_alert to its recorded source video,
 * plus a cache reference for any previously generated clip.
 *
 * One row per alert (unique constraint on behavior_alert_id).
 *
 * Columns:
 *   behavior_alert_id  – FK → behavior_alerts.id
 *   source_video_path  – absolute filesystem path to the recorded source video
 *   video_start_time   – real-world datetime that corresponds to frame 0 of the source video
 *   event_time         – real-world datetime of the alert event (used to compute seek offset)
 *   pre_buffer_sec     – seconds before event_time to include in the clip (default from config)
 *   post_buffer_sec    – seconds after event_time to include in the clip (default from config)
 *   generated_clip_path – absolute path of the cached clip file; NULL if not yet generated
 *   clip_expires_at    – when the cached clip should be considered stale and regenerated
 *
 * Timing contract:
 *   seek_offset  = (event_time − video_start_time) − pre_buffer_sec   [clamped ≥ 0]
 *   clip_duration = pre_buffer_sec + post_buffer_sec
 *
 *   If (event_time − video_start_time) < pre_buffer_sec, the clip starts from
 *   the beginning of the source video rather than a negative offset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_replay_sources', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('behavior_alert_id')->unique();
            $table->string('source_video_path');        // absolute path to recorded source video
            $table->timestamp('video_start_time');      // real-world datetime of frame 0
            $table->timestamp('event_time');            // real-world datetime of the alert event

            $table->unsignedSmallInteger('pre_buffer_sec')
                  ->default(300);                       // 5 min before event
            $table->unsignedSmallInteger('post_buffer_sec')
                  ->default(60);                        // 1 min after event

            $table->string('generated_clip_path')->nullable();  // cached clip, NULL until generated
            $table->timestamp('clip_expires_at')->nullable();   // stale-after timestamp

            $table->timestamps();

            $table->foreign('behavior_alert_id')
                  ->references('id')
                  ->on('behavior_alerts')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_replay_sources');
    }
};
