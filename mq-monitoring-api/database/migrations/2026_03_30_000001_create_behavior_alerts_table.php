<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persists each fired behavior alert for historical display.
 *
 * One row per alert delivery per recipient user.
 * This table is the source of truth for the bell-dropdown history
 * that the Angular frontend loads on session start.
 *
 * Columns:
 *   user_id           – the recipient user (admin or superviseur)
 *   identity_name     – surveillance identity that triggered the alert
 *   alert_type        – 'inactive' | 'phone' | 'late_arrival' | 'early_leave'
 *   duration_minutes  – accumulated / early-by minutes at time of fire
 *   threshold_minutes – configured threshold that was crossed
 *   fired_at          – ISO-8601 timestamp emitted by AlertEvaluationService
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('behavior_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('identity_name', 100);
            $table->string('alert_type', 50);
            $table->float('duration_minutes');
            $table->unsignedSmallInteger('threshold_minutes');
            $table->timestamp('fired_at');
            $table->timestamps();

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->cascadeOnDelete();

            // Index for the primary query pattern: fetch recent alerts per user.
            $table->index(['user_id', 'fired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('behavior_alerts');
    }
};
