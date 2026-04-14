<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds embeddings-pipeline tracking columns to the user_identity_photos table.
 *
 * processing_status transitions:
 *   stored      → photo is on disk, not yet submitted to the embedding service
 *   processing  → GenerateFaceEmbeddingJob is actively running
 *   ready       → embedding generated and stored in identity_face_embeddings
 *   failed      → pipeline rejected or errored; see processing_error for reason
 *
 * This migration is additive-only (no destructive changes to existing cols).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_identity_photos', function (Blueprint $table) {
            // Embedding pipeline state machine.
            $table->string('processing_status', 20)
                ->default('stored')
                ->after('processed_at');

            // Human-readable rejection reason (e.g. "NO_FACE", "MULTIPLE_FACES",
            // or an exception message for transient errors). Null while not failed.
            $table->text('processing_error')
                ->nullable()
                ->after('processing_status');
        });
    }

    public function down(): void
    {
        Schema::table('user_identity_photos', function (Blueprint $table) {
            $table->dropColumn(['processing_status', 'processing_error']);
        });
    }
};
