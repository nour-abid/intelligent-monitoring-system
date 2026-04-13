<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata record for a confirmed alert video clip saved to filesystem storage.
 *
 * Architecture contract: this model never holds video data — only a filesystem
 * reference (file_path) plus clip metadata. Actual video files live on disk.
 *
 * @property int              $id
 * @property int|null         $alert_id           FK → public.behavior_alerts.id (nullable)
 * @property string           $camera_id          Source camera / capture device identifier
 * @property string|null      $identity_name      Recognised surveillance identity in the clip
 * @property string           $event_type         Alert type that triggered the clip
 * @property \Carbon\Carbon   $started_at         First-frame real-world datetime
 * @property \Carbon\Carbon   $ended_at           Last-frame real-world datetime
 * @property int              $duration_sec       Clip length in seconds
 * @property string           $file_path          Absolute filesystem path to the video file
 * @property string           $file_name          Basename of the video file
 * @property int|null         $file_size_bytes    File size in bytes at write time
 * @property string           $mime_type          MIME type (default 'video/mp4')
 * @property int|null         $pre_buffer_sec     Pre-event padding in seconds
 * @property int|null         $post_buffer_sec    Post-event padding in seconds
 * @property string           $clip_status        'pending' | 'ready' | 'failed' | 'expired'
 * @property \Carbon\Carbon|null $retention_until Purge-eligible after this timestamp (null = keep forever)
 */
class SurveillanceClip extends Model
{
    protected $connection = 'surveillance';

    protected $table = 'surveillance_clips';

    protected $fillable = [
        'alert_id',
        'camera_id',
        'identity_name',
        'event_type',
        'started_at',
        'ended_at',
        'duration_sec',
        'file_path',
        'file_name',
        'file_size_bytes',
        'mime_type',
        'pre_buffer_sec',
        'post_buffer_sec',
        'clip_status',
        'retention_until',
    ];

    protected function casts(): array
    {
        return [
            'started_at'      => 'datetime',
            'ended_at'        => 'datetime',
            'retention_until' => 'datetime',
            'duration_sec'    => 'integer',
            'file_size_bytes' => 'integer',
            'pre_buffer_sec'  => 'integer',
            'post_buffer_sec' => 'integer',
        ];
    }

    /**
     * The behavior alert that triggered this clip.
     * Null when the clip was saved without a matched alert record.
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(BehaviorAlert::class, 'alert_id');
    }
}
