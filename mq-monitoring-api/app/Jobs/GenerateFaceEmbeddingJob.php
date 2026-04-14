<?php

namespace App\Jobs;

use App\Models\IdentityFaceEmbedding;
use App\Models\UserIdentityPhoto;
use App\Services\Identity\FaceEmbeddingApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job: generate a face embedding for a single enrollment photo.
 *
 * State machine for the source photo (user_identity_photos.processing_status):
 *   stored → processing → ready    (happy path)
 *   stored → processing → failed   (permanent rejection: NO_FACE etc.)
 *   stored → processing → failed   (transient: retried up to $tries times)
 *
 * Gallery refresh:
 *   On every successful embed, all 'ready' embeddings for the same
 *   surveillance_identity are averaged and written to
 *   models/embeddings/{identity}.npy via POST /gallery/update on the
 *   Python service. This keeps the surveillance runtime's gallery current
 *   without a full retrain cycle.
 *
 * Queue: 'embeddings'
 * Dispatch: IdentityPhotoProcessingService::scheduleProcessing()
 */
class GenerateFaceEmbeddingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum attempts for transient (network / service crash) failures. */
    public int $tries = 3;

    /** Seconds to wait between retry attempts. */
    public int $backoff = 30;

    /**
     * Failure reasons returned by the Python service that are PERMANENT —
     * no retry makes sense; the admin must re-upload a better photo.
     */
    private const PERMANENT_REJECTIONS = ['NO_FACE', 'MULTIPLE_FACES', 'LOW_QUALITY'];

    public function __construct(
        private readonly UserIdentityPhoto $photo
    ) {}

    // ── Main handler ─────────────────────────────────────────────────────

    public function handle(FaceEmbeddingApiClient $client): void
    {
        // Re-fetch to get the latest DB state + eager-load the parent user.
        $photo = UserIdentityPhoto::with('user')->find($this->photo->id);

        if (! $photo || ! $photo->user) {
            // Deleted while queued — discard silently.
            Log::info('[GenerateFaceEmbeddingJob] Photo or user gone — discarding.', [
                'photo_id' => $this->photo->id,
            ]);
            return;
        }

        $identity = $photo->user->surveillance_identity;

        if (empty($identity)) {
            Log::warning('[GenerateFaceEmbeddingJob] No surveillance_identity — aborting.', [
                'photo_id' => $photo->id,
                'user_id'  => $photo->user_id,
            ]);
            $this->setPhotoStatus($photo, 'failed', null, 'User has no surveillance_identity configured.');
            return;
        }

        // ── Step 1: mark as processing ───────────────────────────────────
        $this->setPhotoStatus($photo, 'processing');

        // ── Step 2: call the Python embedding service ─────────────────────
        try {
            $result = $client->embed($photo->id, $photo->absolutePath(), $identity);
        } catch (\Throwable $e) {
            Log::error('[GenerateFaceEmbeddingJob] HTTP call to embedding service failed.', [
                'photo_id' => $photo->id,
                'attempt'  => $this->attempts(),
                'error'    => $e->getMessage(),
            ]);
            $this->setPhotoStatus($photo, 'failed', null, 'INTERNAL_ERROR: ' . $e->getMessage());
            throw $e; // Let Laravel retry.
        }

        // ── Step 3: handle rejection from the service ─────────────────────
        if (! ($result['success'] ?? false)) {
            $this->handleRejection($photo, $identity, $result);
            return;
        }

        // ── Step 4: persist the embedding record ──────────────────────────
        try {
            IdentityFaceEmbedding::updateOrCreate(
                ['photo_id' => $photo->id],
                [
                    'user_id'               => $photo->user_id,
                    'surveillance_identity' => $identity,
                    'model_name'            => $result['model_name']    ?? 'buffalo_l',
                    'embedding_dim'         => $result['embedding_dim'] ?? count($result['embedding']),
                    'embedding_vector'      => $result['embedding'],
                    'quality_score'         => $result['quality_score'] ?? null,
                    'status'                => 'ready',
                    'failure_reason'        => null,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('[GenerateFaceEmbeddingJob] Failed to upsert IdentityFaceEmbedding.', [
                'photo_id' => $photo->id,
                'error'    => $e->getMessage(),
            ]);
            $this->setPhotoStatus($photo, 'failed', null, 'INTERNAL_ERROR: ' . $e->getMessage());
            throw $e;
        }

        // ── Step 5: mark photo ready ──────────────────────────────────────
        $this->setPhotoStatus($photo, 'ready', now());

        Log::info('[GenerateFaceEmbeddingJob] Embedding generated and photo marked ready.', [
            'photo_id'              => $photo->id,
            'user_id'               => $photo->user_id,
            'surveillance_identity' => $identity,
            'quality_score'         => $result['quality_score'] ?? null,
            'embedding_dim'         => $result['embedding_dim'] ?? null,
        ]);

        // ── Step 6: rebuild the .npy gallery for this identity ────────────
        $this->refreshGallery($client, $identity);
    }

    /**
     * Called by Laravel after all retry attempts are exhausted.
     * The photo is already marked 'failed' inside handle(); nothing extra needed.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('[GenerateFaceEmbeddingJob] All retries exhausted.', [
            'photo_id' => $this->photo->id,
            'error'    => $e->getMessage(),
        ]);
    }

    // ── Private helpers ──────────────────────────────────────────────────

    /**
     * Handle a { success: false } response from the embedding service.
     * Permanent rejections (NO_FACE etc.) call $this->fail() to stop retrying.
     */
    private function handleRejection(
        UserIdentityPhoto $photo,
        string            $identity,
        array             $result,
    ): void {
        $reason = $result['failure_reason'] ?? 'UNKNOWN';

        $this->setPhotoStatus($photo, 'failed', null, $reason);

        IdentityFaceEmbedding::updateOrCreate(
            ['photo_id' => $photo->id],
            [
                'user_id'               => $photo->user_id,
                'surveillance_identity' => $identity,
                'model_name'            => 'buffalo_l',
                'embedding_dim'         => 0,
                'embedding_vector'      => [],
                'quality_score'         => null,
                'status'                => 'failed',
                'failure_reason'        => $reason,
            ]
        );

        Log::warning('[GenerateFaceEmbeddingJob] Photo rejected by embedding service.', [
            'photo_id'   => $photo->id,
            'reason'     => $reason,
            'face_count' => $result['face_count'] ?? null,
        ]);

        // Permanent rejections — stop retrying immediately.
        if (in_array($reason, self::PERMANENT_REJECTIONS, true)) {
            $this->fail(new \RuntimeException("Photo rejected: {$reason}"));
        }
    }

    /**
     * Update processing_status (and optionally processed_at / processing_error)
     * using forceFill() so the update is never silently blocked by $fillable.
     */
    private function setPhotoStatus(
        UserIdentityPhoto $photo,
        string            $status,
        ?\Carbon\Carbon   $processedAt = null,
        ?string           $error       = null,
    ): void {
        $photo->forceFill([
            'processing_status' => $status,
            'processed_at'      => $processedAt,
            'processing_error'  => $error,
        ])->save();

        Log::debug('[GenerateFaceEmbeddingJob] Photo status set.', [
            'photo_id' => $photo->id,
            'status'   => $status,
            'error'    => $error,
        ]);
    }

    /**
     * Fetch all 'ready' embedding vectors for $identity from the DB,
     * then call the Python service to compute their mean and write the .npy.
     *
     * Non-fatal: a failure here is logged but does NOT fail the job, since
     * the embedding is already safely stored in the DB.
     */
    private function refreshGallery(FaceEmbeddingApiClient $client, string $identity): void
    {
        $vectors = IdentityFaceEmbedding::where('surveillance_identity', $identity)
            ->where('status', 'ready')
            ->pluck('embedding_vector')
            ->toArray();

        if (empty($vectors)) {
            return;
        }

        try {
            $result = $client->updateGallery($identity, $vectors);
            Log::info('[GenerateFaceEmbeddingJob] Gallery .npy refreshed.', [
                'surveillance_identity' => $identity,
                'vector_count'          => $result['vector_count'] ?? count($vectors),
                'npy_path'              => $result['npy_path']     ?? '—',
            ]);
        } catch (\Throwable $e) {
            // Non-fatal — the embedding is persisted in the DB; the .npy can be
            // rebuilt later via a future artisan command or re-upload trigger.
            Log::error('[GenerateFaceEmbeddingJob] Gallery /gallery/update failed.', [
                'surveillance_identity' => $identity,
                'error'                 => $e->getMessage(),
            ]);
        }
    }
}
