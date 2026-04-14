<?php

namespace App\Services\Identity;

use App\Jobs\GenerateFaceEmbeddingJob;
use App\Models\UserIdentityPhoto;
use Illuminate\Support\Facades\Log;

/**
 * Placeholder service for face embedding generation.
 *
 * CURRENT STATE (Phase 1):
 *   Photos are stored on disk. This service is called after each upload but
 *   performs no heavy computation — it only logs receipt so the pipeline
 *   can later be wired in without changing the controller.
 *
 * FUTURE STATE (Phase 2 — face embedding pipeline):
 *   scheduleProcessing() will dispatch a queued job:
 *
 *     GenerateFaceEmbeddingJob::dispatch($photo)
 *
 *   The job will:
 *     1. Open the image at $photo->absolutePath()
 *     2. Call the ArcFace ONNX model (or a Python microservice)
 *     3. Persist the resulting 512-d float vector to a vectors table
 *        (or update the .npy embedding files used by the surveillance runtime)
 *     4. Set $photo->processed_at = now() and save()
 *
 * CONSISTENCY NOTE:
 *   The surveillance runtime identifies people using the `surveillance_identity`
 *   field on the User model (e.g. "bellaaj"). Embedding generation MUST key the
 *   vector by that same value, not by user_id, so the runtime can look it up by
 *   name without any database join at inference time.
 *
 *   Example: User{id=3, surveillance_identity="bellaaj"} → embed as "bellaaj"
 *   → stored in models/embeddings/bellaaj.npy  (or equivalent vector store)
 */
class IdentityPhotoProcessingService
{
    /**
     * Schedule (or immediately begin) face embedding generation for a photo.
     *
     * In Phase 1 this is a no-op stub that logs receipt.
     * Replace the body with job dispatch when the pipeline is ready.
     *
     * @param UserIdentityPhoto $photo The freshly uploaded photo record.
     */
    public function scheduleProcessing(UserIdentityPhoto $photo): void
    {
        Log::info('[IdentityPhotoProcessing] Dispatching embedding job.', [
            'photo_id'          => $photo->id,
            'user_id'           => $photo->user_id,
            'original_filename' => $photo->original_filename,
            'absolute_path'     => $photo->absolutePath(),
        ]);

        GenerateFaceEmbeddingJob::dispatch($photo)->onQueue('embeddings');
    }

    /**
     * Check whether all unprocessed photos for a user have embeddings ready.
     *
     * Useful for showing a "Ready for recognition" badge in the admin UI.
     *
     * @param int $userId
     * @return bool  true if every photo has a processed_at timestamp
     */
    public function allPhotosProcessed(int $userId): bool
    {
        return ! UserIdentityPhoto::where('user_id', $userId)
            ->whereNull('processed_at')
            ->exists();
    }
}
