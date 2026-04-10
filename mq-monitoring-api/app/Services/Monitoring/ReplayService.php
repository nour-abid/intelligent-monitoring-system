<?php

namespace App\Services\Monitoring;

use App\Models\AlertReplaySource;
use RuntimeException;
use InvalidArgumentException;

/**
 * ReplayService
 *
 * Responsible for generating a short MP4 clip from a recorded source video,
 * centred around an alert event.
 *
 * Clip timing contract
 * --------------------
 * seek_offset  = max(0, (event_time − video_start_time).seconds − pre_buffer_sec)
 * clip_duration = pre_buffer_sec + post_buffer_sec
 *
 * If the event occurred less than pre_buffer_sec into the source video, the clip
 * is silently clamped to start at the beginning of the source (seek_offset = 0)
 * rather than failing — the pre-event section will simply be shorter than requested.
 *
 * The generated clip is written to storage/app/{clips_path}/ and its path is
 * persisted back onto the AlertReplaySource row together with clip_expires_at.
 *
 * A fresh clip is reused across requests until clip_expires_at passes or the
 * file is physically deleted.  Set REPLAY_CACHE_TTL_HOURS=0 to skip caching.
 */
class ReplayService
{
    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Return the absolute path to a ready clip, generating it if necessary.
     *
     * @throws InvalidArgumentException If timing is logically impossible
     *                                  (e.g. event_time before video_start_time).
     * @throws RuntimeException         If the source file is missing or ffmpeg fails.
     */
    public function getOrGenerate(AlertReplaySource $source): string
    {
        if ($this->cacheIsValid($source)) {
            return $source->generated_clip_path;
        }

        $clipPath = $this->generateClip($source);

        $ttl = (int) config('replay.cache_ttl_hours', 24);

        $source->update([
            'generated_clip_path' => $clipPath,
            'clip_expires_at'     => $ttl > 0
                ? now()->addHours($ttl)
                : null,    // TTL=0 means "never cache; always regenerate"
        ]);

        return $clipPath;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * True when a previously generated clip still exists on disk and hasn't expired.
     */
    private function cacheIsValid(AlertReplaySource $source): bool
    {
        if (empty($source->generated_clip_path) || !file_exists($source->generated_clip_path)) {
            return false;
        }

        // clip_expires_at = NULL means the cache is intentionally disabled (TTL=0).
        if ($source->clip_expires_at === null) {
            return false;
        }

        return $source->clip_expires_at->isFuture();
    }

    /**
     * Generate the clip using ffmpeg and return the absolute path to the output file.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function generateClip(AlertReplaySource $source): string
    {
        // 1. Validate source file exists
        if (!file_exists($source->source_video_path)) {
            throw new RuntimeException(
                'Source video not found: ' . $source->source_video_path
            );
        }

        // 2. Compute seek offset
        //    event_time must be >= video_start_time for a valid window.
        $videoOffsetSec = $source->video_start_time->diffInSeconds($source->event_time, absolute: false);

        if ($videoOffsetSec < 0) {
            throw new InvalidArgumentException(
                sprintf(
                    'event_time (%s) is before video_start_time (%s) — cannot compute seek offset.',
                    $source->event_time->toIso8601String(),
                    $source->video_start_time->toIso8601String()
                )
            );
        }

        // Clamp: if the event is close to the start, reduce the pre-buffer silently.
        $seekOffset    = max(0, $videoOffsetSec - $source->pre_buffer_sec);
        $clipDuration  = $source->pre_buffer_sec + $source->post_buffer_sec;

        if ($clipDuration <= 0) {
            throw new InvalidArgumentException(
                'clip_duration must be > 0 (pre_buffer_sec + post_buffer_sec = ' . $clipDuration . ')'
            );
        }

        // 3. Prepare output directory and path
        $clipsDir = storage_path('app/' . ltrim(config('replay.clips_path', 'replay_cache'), '/'));

        if (!is_dir($clipsDir) && !mkdir($clipsDir, 0755, true) && !is_dir($clipsDir)) {
            throw new RuntimeException('Could not create clips directory: ' . $clipsDir);
        }

        // Remove the previously cached file for this alert to avoid stale orphans.
        if (!empty($source->generated_clip_path) && file_exists($source->generated_clip_path)) {
            @unlink($source->generated_clip_path);
        }

        $filename   = sprintf('alert_%d_%d.mp4', $source->behavior_alert_id, time());
        $outputPath = $clipsDir . DIRECTORY_SEPARATOR . $filename;

        // 4. Build and run ffmpeg command
        //
        //    Flags:
        //      -y              Overwrite output without asking.
        //      -ss <offset>    Seek before opening the input (fast, key-frame accurate).
        //      -i <source>     Input file.
        //      -t <duration>   Stop after this many seconds.
        //      -c copy         Stream copy (no re-encode) — fast and lossless quality.
        //      2>&1            Merge stderr into stdout so we can capture error messages.
        //
        //    Note: placing -ss before -i uses fast seek (to the nearest keyframe).
        //    The actual start may be up to a few seconds earlier than seekOffset on
        //    keyframe boundaries — this is acceptable for surveillance replay.
        //    Use -ss after -i and -c:v libx264 to get exact-frame seek if needed later.
        //
        $ffmpeg = config('replay.ffmpeg_binary', 'ffmpeg');

        $cmd = implode(' ', [
            escapeshellarg($ffmpeg),
            '-y',
            '-ss', (int) $seekOffset,
            '-i', escapeshellarg($source->source_video_path),
            '-t', (int) $clipDuration,
            '-c copy',
            escapeshellarg($outputPath),
            '2>&1',
        ]);

        exec($cmd, $cmdOutput, $exitCode);

        if ($exitCode !== 0 || !file_exists($outputPath) || filesize($outputPath) === 0) {
            // Include the last few lines of ffmpeg output for diagnosability.
            $tail = implode("\n", array_slice($cmdOutput, -8));
            throw new RuntimeException(
                "ffmpeg exited with code {$exitCode}. Last output:\n{$tail}"
            );
        }

        return $outputPath;
    }
}
