<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the identity_face_embeddings table.
 *
 * One row per photo that the embedding pipeline has processed.
 * Multiple rows per user (one per enrolled photo) are normal — the gallery
 * .npy file used by the surveillance runtime is the L2-normalised MEAN of
 * all 'ready' rows for a given surveillance_identity.
 *
 * Design decisions:
 *   - embedding_vector stored as JSON for portability; migrate to pgvector later
 *     without breaking the Eloquent model (just swap the column type + cast).
 *   - photo_id is nullable FK (nullOnDelete) — if an admin deletes a photo the
 *     embedding record is KEPT because it still contributes to the gallery mean.
 *   - surveillance_identity is denormalized so queries never need a join at
 *     gallery-refresh time.
 *   - status is 'ready' or 'failed'; failure_reason carries the NO_FACE etc.
 *     string so admins can surface it in the UI without reading Laravel logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_face_embeddings', function (Blueprint $table) {
            $table->id();

            // Parent user — cascade so orphaned embeddings are impossible.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Source photo — nullable so the embedding survives photo deletion.
            $table->foreignId('photo_id')
                ->nullable()
                ->constrained('user_identity_photos')
                ->nullOnDelete();

            // Identity key that maps to models/embeddings/{surveillance_identity}.npy
            // Must equal User::surveillance_identity — denormalized for speed.
            $table->string('surveillance_identity');

            // InsightFace model that produced this vector. Allows multi-model
            // gallery splits in a future iteration.
            $table->string('model_name', 50)->default('buffalo_l');

            // Dimensionality — 512 for buffalo_l. Stored to make the row
            // self-describing without reading the vector itself.
            $table->unsignedSmallInteger('embedding_dim');

            // 512-d L2-normalised float vector as JSON array.
            // Path to pgvector: ALTER COLUMN embedding_vector TYPE vector(512) USING ...
            $table->json('embedding_vector');

            // InsightFace det_score [0, 1]. Higher = more confident frontal face.
            // Useful for filtering low-quality embeddings from the gallery mean.
            $table->float('quality_score')->nullable();

            // ready | failed
            $table->string('status', 20);

            // NO_FACE | MULTIPLE_FACES | LOW_QUALITY | INTERNAL_ERROR
            // Human-readable so the frontend can surface it directly.
            $table->text('failure_reason')->nullable();

            $table->timestamps();

            // Fetch all ready embeddings for one identity (gallery refresh).
            $table->index(['surveillance_identity', 'status']);
            // Fetch all embeddings for one user (admin UI).
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_face_embeddings');
    }
};
