<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the surveillance_identity column to the users table.
 *
 * This decouples surveillance data ownership from the human users.name
 * field and lets admins map each account to the exact identity token
 * produced by the Python recognition runtime (e.g. "bellaaj", "alice").
 *
 * Rules:
 *  - Nullable  — a user with no mapping sees no surveillance data when scoped.
 *  - Unique    — two accounts cannot share the same identity token (NULLs are
 *               not compared by the unique constraint in SQLite, so multiple
 *               unmapped users are allowed).
 *  - max 100   — consistent with recognition runtime name lengths.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('surveillance_identity', 100)
                ->nullable()
                ->unique()
                ->after('supervisor_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('surveillance_identity');
        });
    }
};
