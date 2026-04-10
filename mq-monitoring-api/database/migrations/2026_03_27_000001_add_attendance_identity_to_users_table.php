<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the attendance_identity column to the users table.
 *
 * This provides an explicit, admin-maintained mapping between a user account
 * and the person token used in the attendance database (attendance_events.person).
 * It avoids fragile inference from users.name and is the safe foundation for
 * future attendance-based alert logic (e.g. late arrival detection).
 *
 * Rules:
 *  - Nullable  — a user with no mapping is simply excluded from attendance alerts.
 *  - Unique    — two accounts cannot share the same attendance token (NULLs are
 *               not compared by the unique constraint in SQLite, so multiple
 *               unmapped users are allowed).
 *  - max 100   — consistent with attendance runtime name lengths.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('attendance_identity', 100)
                ->nullable()
                ->unique()
                ->after('surveillance_identity');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('attendance_identity');
        });
    }
};
