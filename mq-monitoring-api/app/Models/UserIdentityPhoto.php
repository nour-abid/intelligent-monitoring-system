<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Represents a single facial enrollment photo uploaded by an admin.
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $disk
 * @property string      $path
 * @property string      $original_filename
 * @property string      $mime_type
 * @property int         $size_bytes
 * @property \Carbon\Carbon|null $processed_at
 * @property string      $processing_status   stored|processing|ready|failed
 * @property string|null $processing_error    rejection reason or exception message
 * @property \Carbon\Carbon      $created_at
 * @property \Carbon\Carbon      $updated_at
 */
class UserIdentityPhoto extends Model
{
    protected $fillable = [
        'user_id',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'processed_at',
        'processing_status',
        'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'size_bytes'   => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** Absolute filesystem path — usable by the embedding pipeline. */
    public function absolutePath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    /** Public URL for serving the image through the API (temporary signed URL or direct). */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /** Whether the embedding pipeline has already processed this photo. */
    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    public function isReady(): bool
    {
        return $this->processing_status === 'ready';
    }

    public function isFailed(): bool
    {
        return $this->processing_status === 'failed';
    }
}
