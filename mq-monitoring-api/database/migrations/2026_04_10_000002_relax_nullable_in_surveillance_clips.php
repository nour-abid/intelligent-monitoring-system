<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relaxes NOT NULL constraints on surveillance.surveillance_clips columns
 * that cannot always be populated from the Laravel replay path:
 *
 *   camera_id  — not available when a clip is generated on-demand by ReplayService.
 *                The Python surveillance pipeline sets this when writing clips directly.
 *
 *   file_path  — null for clips whose generation failed (clip_status = 'failed').
 *   file_name  — null for the same reason.
 *
 * These changes are backward-compatible: existing 'ready' rows already have
 * non-null values for all three columns.
 */
return new class extends Migration
{
    protected $connection = 'surveillance';

    public function up(): void
    {
        Schema::connection('surveillance')->table('surveillance_clips', function (Blueprint $table) {
            $table->string('camera_id', 100)->nullable()->change();
            $table->string('file_path')->nullable()->change();
            $table->string('file_name', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('surveillance')->table('surveillance_clips', function (Blueprint $table) {
            // Revert to NOT NULL — only safe if no null rows exist.
            $table->string('camera_id', 100)->nullable(false)->change();
            $table->string('file_path')->nullable(false)->change();
            $table->string('file_name', 255)->nullable(false)->change();
        });
    }
};
