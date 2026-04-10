<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks the last time an alert was fired for a given employee + activity pair.
 * Enforces the cooldown window so the same alert cannot fire more than once
 * per cooldown period.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_cooldowns', function (Blueprint $table) {
            $table->id();
            $table->string('identity_name', 100);
            $table->string('alert_type', 50);        // 'inactive' | 'phone'
            $table->timestamp('last_alerted_at');
            $table->unique(['identity_name', 'alert_type']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_cooldowns');
    }
};
