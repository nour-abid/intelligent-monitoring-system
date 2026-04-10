<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata that links a BehaviorAlert to a recorded source video
 * and optionally to a previously generated replay clip.
 *
 * @property int              $id
 * @property int              $behavior_alert_id
 * @property string           $source_video_path   Absolute path to the recorded source video file
 * @property \Carbon\Carbon   $video_start_time    Real-world datetime of frame 0 of the source video
 * @property \Carbon\Carbon   $event_time          Real-world datetime of the alert event
 * @property int              $pre_buffer_sec      Seconds before event_time included in the clip
 * @property int              $post_buffer_sec     Seconds after event_time included in the clip
 * @property string|null      $generated_clip_path Absolute path of the cached clip, or null
 * @property \Carbon\Carbon|null $clip_expires_at  When the cached clip becomes stale
 */
class AlertReplaySource extends Model
{
    protected $fillable = [
        'behavior_alert_id',
        'source_video_path',
        'video_start_time',
        'event_time',
        'pre_buffer_sec',
        'post_buffer_sec',
        'generated_clip_path',
        'clip_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'video_start_time'    => 'datetime',
            'event_time'          => 'datetime',
            'clip_expires_at'     => 'datetime',
            'pre_buffer_sec'      => 'integer',
            'post_buffer_sec'     => 'integer',
        ];
    }

    public function behaviorAlert(): BelongsTo
    {
        return $this->belongsTo(BehaviorAlert::class);
    }
}
