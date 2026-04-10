<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores short-lived OTP codes used exclusively for the forgot/reset-password flow.
 *
 * One row per email address — updateOrCreate replaces any previous pending code,
 * so a user can resend without accumulating stale rows.
 *
 * The OTP itself is never stored in plain text; only its bcrypt hash is persisted.
 * Expiry is enforced in the controller.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_otps', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique()->index();
            $table->string('otp_hash');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_otps');
    }
};
