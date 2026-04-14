<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserIdentityPhoto;
use App\Services\Identity\IdentityPhotoProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\File;

/**
 * Admin-only controller for managing facial enrollment photos.
 *
 * Routes:
 *   POST   /api/users/{userId}/photos        → upload one or many photos
 *   GET    /api/users/{userId}/photos        → list photos for a user
 *   DELETE /api/photos/{photoId}             → delete one photo
 *   GET    /api/photos/{photoId}/image       → serve the actual image
 */
class IdentityPhotoController extends Controller
{
    private const DISK          = 'identity_photos';
    private const MAX_SIZE_KB   = 5_120;   // 5 MB per file
    private const MIN_PER_USER  = 5;       // minimum for embedding to work
    private const MAX_PER_USER  = 20;      // cap to prevent abuse
    private const ALLOWED_MIME  = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly IdentityPhotoProcessingService $processor
    ) {}

    // ── POST /api/users/{userId}/photos ──────────────────────────────────

    /**
     * Upload one or more enrollment photos for a user.
     *
     * Accepts multipart/form-data with key `photos[]` (array of files).
     * Returns the newly created photo records.
     */
    public function store(Request $request, int $userId): JsonResponse
    {
        $user = User::findOrFail($userId);

        $request->validate([
            'photos'   => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => [
                'required',
                File::types(self::ALLOWED_MIME)->max(self::MAX_SIZE_KB),
                // Explicitly reject .jfif — browsers report it as image/jpeg
                // so MIME validation alone does not catch it.
                function (string $attribute, mixed $value, \Closure $fail) {
                    $ext = strtolower(pathinfo($value->getClientOriginalName(), PATHINFO_EXTENSION));
                    if ($ext === 'jfif') {
                        $fail("JFIF files are not supported. Use JPEG, PNG, or WebP.");
                    }
                },
            ],
        ]);

        // Cap total photos per user.
        $current = $user->identityPhotos()->count();
        if ($current + count($request->file('photos')) > self::MAX_PER_USER) {
            return response()->json([
                'message' => "A user may have at most " . self::MAX_PER_USER . " enrollment photos. "
                           . "Currently {$current} exist.",
            ], 422);
        }

        // Enforce minimum photo count — embedding requires at least MIN_PER_USER.
        $totalAfterUpload = $current + count($request->file('photos'));
        if ($totalAfterUpload < self::MIN_PER_USER) {
            $stillNeeded = self::MIN_PER_USER - $current;
            return response()->json([
                'message' => "Face enrollment requires at least " . self::MIN_PER_USER . " photos total. "
                           . "Currently {$current} exist — upload at least {$stillNeeded} more.",
            ], 422);
        }

        // Warn when the user has no surveillance_identity — photos will be
        // unassociated with any known embedding slot for the Python pipeline.
        if (empty($user->surveillance_identity)) {
            Log::warning('[IdentityPhoto] Upload for user without surveillance_identity.', [
                'user_id' => $user->id,
                'name'    => $user->name,
            ]);
        }

        $created = [];

        foreach ($request->file('photos') as $file) {
            // Store file first; track the path so we can roll back on DB failure.
            $path = $file->store("{$userId}", self::DISK);

            try {
                $photo = UserIdentityPhoto::create([
                    'user_id'           => $user->id,
                    'disk'              => self::DISK,
                    'path'              => $path,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type'         => $file->getMimeType(),
                    'size_bytes'        => $file->getSize(),
                    // Explicitly set the initial status so the Eloquent object
                    // reflects 'stored' without relying on the DB DEFAULT and
                    // the null-coalescing fallback in photoPayload().
                    'processing_status' => 'stored',
                ]);
            } catch (\Throwable $e) {
                // Roll back the stored file so no orphan is left on disk.
                Storage::disk(self::DISK)->delete($path);
                Log::error('[IdentityPhoto] DB insert failed — file rolled back.', [
                    'user_id' => $userId,
                    'path'    => $path,
                    'error'   => $e->getMessage(),
                ]);
                throw $e;
            }

            Log::info('[IdentityPhoto] Photo uploaded.', [
                'photo_id'             => $photo->id,
                'user_id'             => $user->id,
                'surveillance_identity' => $user->surveillance_identity,
                'original_filename'   => $photo->original_filename,
                'size_bytes'          => $photo->size_bytes,
            ]);

            // Hook: schedule for future embedding generation without blocking.
            $this->processor->scheduleProcessing($photo);

            $created[] = $this->photoPayload($photo);
        }

        return response()->json(['photos' => $created], 201);
    }

    // ── GET /api/users/{userId}/photos ───────────────────────────────────

    /** List all enrollment photos for a user. */
    public function index(int $userId): JsonResponse
    {
        $user   = User::findOrFail($userId);
        $photos = $user->identityPhotos()
            ->get()
            ->map(fn(UserIdentityPhoto $p) => $this->photoPayload($p))
            ->values();

        return response()->json([
            'photos'            => $photos,
            'enrollment_status' => $this->userEnrollmentStatus($user),
        ]);
    }

    // ── DELETE /api/photos/{photoId} ─────────────────────────────────────

    /** Permanently delete one photo (record + file on disk). */
    public function destroy(int $photoId): Response
    {
        $photo = UserIdentityPhoto::findOrFail($photoId);

        // Attempt to remove the file; log if it is already gone but do not abort —
        // the DB row must always be cleaned up regardless of disk state.
        $disk    = Storage::disk($photo->disk);
        $deleted = $disk->exists($photo->path)
            ? $disk->delete($photo->path)
            : true; // already absent — treat as success

        if (! $deleted) {
            Log::warning('[IdentityPhoto] File could not be deleted from disk.', [
                'photo_id' => $photo->id,
                'path'     => $photo->path,
                'disk'     => $photo->disk,
            ]);
        }

        $photo->delete();

        Log::info('[IdentityPhoto] Photo deleted.', [
            'photo_id' => $photoId,
            'user_id'  => $photo->user_id,
            'path'     => $photo->path,
        ]);

        return response()->noContent();
    }

    // ── GET /api/photos/{photoId}/image ─────────────────────────────────

    /**
     * Stream the actual image file to the browser.
     *
     * This keeps image URLs internal to the API (no public web root exposure)
     * while still serving them with the correct Content-Type.
     */
    public function image(int $photoId): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $photo    = UserIdentityPhoto::findOrFail($photoId);
        $disk     = Storage::disk($photo->disk);
        $fullPath = $disk->path($photo->path);

        if (! $disk->exists($photo->path)) {
            abort(404, 'Image file not found on disk.');
        }

        return response()->file($fullPath, [
            'Content-Type'  => $photo->mime_type,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    // ── Private helpers ──────────────────────────────────────────────────

    private function photoPayload(UserIdentityPhoto $photo): array
    {
        return [
            'id'                => $photo->id,
            'user_id'           => $photo->user_id,
            'original_filename' => $photo->original_filename,
            'mime_type'         => $photo->mime_type,
            'size_bytes'        => $photo->size_bytes,
            'processing_status' => $photo->processing_status ?? 'stored',
            'processing_error'  => $photo->processing_error,
            'processed_at'      => $photo->processed_at?->toIso8601String(),
            'created_at'        => $photo->created_at->toIso8601String(),
            // Image URL routes through our API — no direct disk path exposed.
            'url'               => route('photos.image', $photo->id),
        ];
    }

    /**
     * Helper used by index() to enrich the user payload with:
     * - whether the parent user has a surveillance_identity set
     * This allows the frontend to surface a "No identity mapped" warning
     * when photos exist but no embedding slot is configured.
     */
    private function userEnrollmentStatus(User $user): array
    {
        return [
            'surveillance_identity' => $user->surveillance_identity,
            'has_identity_mapping'  => ! empty($user->surveillance_identity),
        ];
    }
}
