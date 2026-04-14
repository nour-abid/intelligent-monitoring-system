<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the user_identity_photos table.
 *
 * Each row is one image that an admin has uploaded for a user's facial
 * identity enrollment. Multiple photos per user are supported to give the
 * embedding pipeline a richer training set.
 *
 * Design decisions:
 * - Files are stored on disk (not in the DB); only metadata lives here.
 * - `disk` records which Laravel filesystem disk holds the file, making
 *   it safe to migrate storage backends without breaking existing rows.
 * - `processed_at` is null until the embedding pipeline (future) has
 *   consumed the image; non-null means a valid vector exists.
 * - Soft-deletion is NOT used: hard delete + disk cleanup is cleaner for
 *   large binary assets and avoids orphaned files on disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_identity_photos', function (Blueprint $table) {
            $table->id();

            // Owner — cascades so orphaned rows are impossible.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Storage reference — disk + relative path, never a URL.
            $table->string('disk')->default('identity_photos'); // matches filesystems.php
            $table->string('path');                            // e.g. "photos/5/uuid.jpg"

            // Human-readable original filename for display only.
            $table->string('original_filename');

            // MIME type and size (bytes) — validated on upload, cached here.
            $table->string('mime_type', 50);
            $table->unsignedInteger('size_bytes');

            // Embedding pipeline hook (future).
            // null  = not yet processed
            // value = timestamp when the pipeline consumed this image
            $table->timestamp('processed_at')->nullable();

            $table->timestamps(); // created_at = upload timestamp
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identity_photos');
    }
};
