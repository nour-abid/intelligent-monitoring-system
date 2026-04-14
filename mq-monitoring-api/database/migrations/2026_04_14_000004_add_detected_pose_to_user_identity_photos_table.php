<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_identity_photos', function (Blueprint $table) {
            $table->string('detected_pose', 20)
                  ->nullable()
                  ->after('processing_error');
        });
    }

    public function down(): void
    {
        Schema::table('user_identity_photos', function (Blueprint $table) {
            $table->dropColumn('detected_pose');
        });
    }
};
