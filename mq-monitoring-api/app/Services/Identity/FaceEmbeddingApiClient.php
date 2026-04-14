<?php

namespace App\Services\Identity;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the Python face-embedding microservice.
 *
 * The service (embedding-service/main.py) runs as a separate FastAPI process.
 * Configure its address in .env:
 *
 *   EMBEDDING_SERVICE_URL=http://127.0.0.1:8765
 *
 * Two endpoints are used:
 *   POST /embed          — detect face, return 512-d vector
 *   POST /gallery/update — average all vectors and write {identity}.npy
 */
class FaceEmbeddingApiClient
{
    private readonly string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('embedding.service_url'), '/');
    }

    /**
     * Ask the Python service to detect the face in $imagePath and return a
     * 512-d L2-normalised InsightFace embedding.
     *
     * @param  int    $photoId              DB id of the source UserIdentityPhoto.
     * @param  string $imagePath            Absolute path the Python service can read.
     * @param  string $surveillanceIdentity User's identity key (e.g. "bellaaj").
     * @return array  Decoded JSON — shape depends on success/failure:
     *
     *   Success:
     *     { success: true, photo_id, surveillance_identity, embedding: float[512],
     *       embedding_dim: 512, model_name: "buffalo_l", quality_score: float,
     *       face_count: 1 }
     *
     *   Failure:
     *     { success: false, photo_id, failure_reason: "NO_FACE"|"MULTIPLE_FACES"
     *       |"LOW_QUALITY", face_count: int }
     *
     * @throws \Illuminate\Http\Client\RequestException on HTTP-level failure.
     */
    public function embed(int $photoId, string $imagePath, string $surveillanceIdentity): array
    {
        Log::info('[EmbeddingApiClient] Calling /embed.', [
            'photo_id'              => $photoId,
            'surveillance_identity' => $surveillanceIdentity,
            'image_path'            => $imagePath,
        ]);

        $response = Http::timeout(60)
            ->asJson()
            ->post("{$this->baseUrl}/embed", [
                'photo_id'              => $photoId,
                'image_path'            => $imagePath,
                'surveillance_identity' => $surveillanceIdentity,
            ])
            ->throw();

        return $response->json();
    }

    /**
     * Ask the Python service to compute the L2-normalised mean of $vectors
     * and write (or overwrite) the result to:
     *   {EMBEDDINGS_DIR}/{$surveillanceIdentity}.npy
     *
     * This file is read by the surveillance runtime's IdentityMatcher.
     * Called once after every successful embed() so the gallery is always fresh.
     *
     * @param  string    $surveillanceIdentity  Must match the .npy stem exactly.
     * @param  float[][] $vectors               Array of 512-d float arrays.
     * @return array     { success, surveillance_identity, vector_count, npy_path }
     *
     * @throws \Illuminate\Http\Client\RequestException on HTTP-level failure.
     */
    public function updateGallery(string $surveillanceIdentity, array $vectors): array
    {
        Log::info('[EmbeddingApiClient] Calling /gallery/update.', [
            'surveillance_identity' => $surveillanceIdentity,
            'vector_count'          => count($vectors),
        ]);

        $response = Http::timeout(30)
            ->asJson()
            ->post("{$this->baseUrl}/gallery/update", [
                'surveillance_identity' => $surveillanceIdentity,
                'vectors'               => $vectors,
            ])
            ->throw();

        return $response->json();
    }

    /**
     * Ask the Python service to detect a face in $imagePath and return its
     * classified head pose — no embedding computation.
     *
     * Used by the backfill command to populate detected_pose for older photos.
     *
     * @return array { photo_id, success, detected_pose, face_count, failure_reason }
     * @throws \Illuminate\Http\Client\RequestException on HTTP-level failure.
     */
    public function classifyPose(int $photoId, string $imagePath): array
    {
        $response = Http::timeout(30)
            ->asJson()
            ->post("{$this->baseUrl}/classify-pose", [
                'photo_id'   => $photoId,
                'image_path' => $imagePath,
            ])
            ->throw();

        return $response->json();
    }
}
