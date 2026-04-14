<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One face embedding record, generated from a single enrollment photo.
 *
 * Multiple rows may exist per user — one per processed photo.
 * The surveillance runtime uses the L2-normalised MEAN of all 'ready'
 * embeddings for a given surveillance_identity, written to a .npy file by
 * the Python embedding service's /gallery/update endpoint.
 *
 * @property int         $id
 * @property int         $user_id
 * @property int|null    $photo_id           null if the source photo was deleted
 * @property string      $surveillance_identity
 * @property string      $model_name         e.g. "buffalo_l"
 * @property int         $embedding_dim      512 for buffalo_l
 * @property array       $embedding_vector   512-d L2-normalised float array
 * @property float|null  $quality_score      InsightFace det_score [0,1]
 * @property string      $status             ready | failed
 * @property string|null $failure_reason     NO_FACE | MULTIPLE_FACES | LOW_QUALITY | INTERNAL_ERROR
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class IdentityFaceEmbedding extends Model
{
    protected $fillable = [
        'user_id',
        'photo_id',
        'surveillance_identity',
        'model_name',
        'embedding_dim',
        'embedding_vector',
        'quality_score',
        'status',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'embedding_vector' => 'array',
            'quality_score'    => 'float',
            'embedding_dim'    => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(UserIdentityPhoto::class, 'photo_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
