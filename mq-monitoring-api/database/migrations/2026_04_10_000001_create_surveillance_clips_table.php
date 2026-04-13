<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates surveillance.surveillance_clips — the metadata store for confirmed
 * alert video clips saved to filesystem-level storage.
 *
 * Architecture contract:
 *   • PostgreSQL stores ONLY metadata + filesystem reference (file_path).
 *   • Actual video files live on disk / hardware storage.
 *   • This table is the source of truth for clip lifecycle management.
 *
 * Columns:
 *   alert_id          – nullable FK → public.behavior_alerts.id (SET NULL on delete)
 *   camera_id         – identifier of the source camera or capture device
 *   identity_name     – surveillance identity in the clip (null if unrecognised)
 *   event_type        – alert type that triggered the clip ('inactive', 'phone', etc.)
 *   started_at        – real-world timestamp of the first frame in the clip
 *   ended_at          – real-world timestamp of the last frame in the clip
 *   duration_sec      – pre-computed clip length in seconds (ended_at − started_at)
 *   file_path         – absolute filesystem path to the saved video file
 *   file_name         – basename of the file (for display without parsing file_path)
 *   file_size_bytes   – optional: size of the video file reported at write time
 *   mime_type         – MIME type (default 'video/mp4')
 *   pre_buffer_sec    – seconds of footage captured before the triggering event
 *   post_buffer_sec   – seconds of footage captured after the triggering event
 *   clip_status       – lifecycle state: pending | ready | failed | expired
 *   retention_until   – if set, clip and file may be purged after this timestamp
 */
return new class extends Migration
{
    protected $connection = 'surveillance';

    public function up(): void
    {
        Schema::connection('surveillance')->create('surveillance_clips', function (Blueprint $table) {
            $table->id();

            // Alert linkage — nullable so clips can exist without a matched alert record.
            $table->unsignedBigInteger('alert_id')->nullable();

            $table->string('camera_id', 100);
            $table->string('identity_name', 100)->nullable();
            $table->string('event_type', 50);

            // Temporal bounds of the clip.
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->unsignedInteger('duration_sec');

            // Filesystem reference — NO video data stored here.
            $table->string('file_path');
            $table->string('file_name', 255);
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('mime_type', 50)->default('video/mp4');

            // Buffer metadata — how much padding surrounds the event moment in the clip.
            $table->unsignedSmallInteger('pre_buffer_sec')->nullable();
            $table->unsignedSmallInteger('post_buffer_sec')->nullable();

            // Lifecycle state.
            $table->string('clip_status', 20)->default('pending');

            // Retention policy — null means keep indefinitely.
            $table->timestamp('retention_until')->nullable();

            $table->timestamps();

            // Foreign key to the triggering alert in the public schema.
            // ON DELETE SET NULL so clips survive if their alert record is removed.
            $table->foreign('alert_id')
                  ->references('id')
                  ->on('public.behavior_alerts')
                  ->nullOnDelete();

            // ── Indexes ───────────────────────────────────────────────────
            // alert_id: fast look-up of clips for a given alert.
            $table->index('alert_id');

            // identity + time: query clips per person over a date range.
            $table->index(['identity_name', 'started_at']);

            // event_type + time: filter clips by alert type over a date range.
            $table->index(['event_type', 'started_at']);

            // camera + time: retrieve clip history per device.
            $table->index(['camera_id', 'started_at']);

            // retention_until: efficient scheduled purge queries.
            $table->index('retention_until');
        });
    }

    public function down(): void
    {
        Schema::connection('surveillance')->dropIfExists('surveillance_clips');
    }
};
