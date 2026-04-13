<?php

namespace App\Services\Monitoring;

use App\Models\AlertReplaySource;
use App\Models\BehaviorAlert;
use App\Models\SurveillanceClip;
use Illuminate\Support\Facades\Log;

/**
 * ClipMetadataService
 *
 * Responsible for writing SurveillanceClip metadata rows when confirmed alert
 * clips are finalized by ReplayService.
 *
 * Architecture contract:
 *   - This service writes ONLY metadata. Actual video files live on disk.
 *   - All public methods are non-throwing: DB failures are logged and swallowed
 *     so that the calling stream response is never broken by a metadata write error.
 *   - Only confirmed clips (those that produced a real file) are recorded as 'ready'.
 *   - Failed generations record a 'failed' row only if no row yet exists for the alert,
 *     so that a pre-existing 'ready' row is never downgraded.
 */
class ClipMetadataService
{
    /**
     * Persist (or refresh) the SurveillanceClip metadata for a successfully generated clip.
     *
     * Uses updateOrCreate keyed on alert_id so that clip regenerations (after cache
     * expiry) keep the metadata row current rather than leaving stale file references.
     *
     * @param BehaviorAlert   $alert    The alert that triggered the clip.
     * @param AlertReplaySource $source The replay timing metadata for this alert.
     * @param string          $clipPath Absolute filesystem path to the generated file.
     */
    public function ensureRecorded(
        BehaviorAlert     $alert,
        AlertReplaySource $source,
        string            $clipPath,
    ): void {
        try {
            $fileSizeBytes = file_exists($clipPath) ? filesize($clipPath) : null;

            SurveillanceClip::updateOrCreate(
                ['alert_id' => $alert->id],
                [
                    'camera_id'       => null,   // not available in the on-demand replay path
                    'identity_name'   => $alert->identity_name,
                    'event_type'      => $alert->alert_type,
                    'started_at'      => $source->event_time->copy()->subSeconds($source->pre_buffer_sec),
                    'ended_at'        => $source->event_time->copy()->addSeconds($source->post_buffer_sec),
                    'duration_sec'    => $source->pre_buffer_sec + $source->post_buffer_sec,
                    'file_path'       => $clipPath,
                    'file_name'       => basename($clipPath),
                    'file_size_bytes' => $fileSizeBytes,
                    'mime_type'       => 'video/mp4',
                    'pre_buffer_sec'  => $source->pre_buffer_sec,
                    'post_buffer_sec' => $source->post_buffer_sec,
                    'clip_status'     => 'ready',
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('[surveillance_clips] Failed to persist clip metadata', [
                'alert_id'  => $alert->id,
                'clip_path' => $clipPath,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record a failed clip generation attempt.
     *
     * Uses firstOrCreate so that a pre-existing 'ready' row (from a successful
     * earlier generation) is never overwritten with a 'failed' status.
     *
     * @param BehaviorAlert     $alert  The alert whose clip generation failed.
     * @param AlertReplaySource $source The replay source that was attempted.
     * @param string            $reason The failure reason (ffmpeg error, missing file, etc.).
     */
    public function recordFailure(
        BehaviorAlert     $alert,
        AlertReplaySource $source,
        string            $reason,
    ): void {
        try {
            SurveillanceClip::firstOrCreate(
                ['alert_id' => $alert->id],
                [
                    'camera_id'       => null,
                    'identity_name'   => $alert->identity_name,
                    'event_type'      => $alert->alert_type,
                    'started_at'      => $source->event_time->copy()->subSeconds($source->pre_buffer_sec),
                    'ended_at'        => $source->event_time->copy()->addSeconds($source->post_buffer_sec),
                    'duration_sec'    => $source->pre_buffer_sec + $source->post_buffer_sec,
                    'file_path'       => null,
                    'file_name'       => null,
                    'file_size_bytes' => null,
                    'mime_type'       => 'video/mp4',
                    'pre_buffer_sec'  => $source->pre_buffer_sec,
                    'post_buffer_sec' => $source->post_buffer_sec,
                    'clip_status'     => 'failed',
                ]
            );

            Log::notice('[surveillance_clips] Recorded failed clip for alert ' . $alert->id, [
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[surveillance_clips] Failed to record clip failure', [
                'alert_id' => $alert->id,
                'error'    => $e->getMessage(),
                'reason'   => $reason,
            ]);
        }
    }
}
