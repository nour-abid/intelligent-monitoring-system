<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Placed after supervisor_id for logical grouping with access-control fields.
            // Default true: existing rows (including the seeded admin) become active
            // automatically so that no manual backfill is required.
            $table->boolean('is_active')->default(true)->after('supervisor_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
