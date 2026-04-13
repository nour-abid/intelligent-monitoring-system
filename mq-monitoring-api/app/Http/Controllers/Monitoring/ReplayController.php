<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\AlertReplaySource;
use App\Models\BehaviorAlert;
use App\Services\Monitoring\ClipMetadataService;
use App\Services\Monitoring\ReplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ReplayController
 *
 * Two actions:
 *
 *   POST  /api/monitoring/surveillance/alerts/{alertId}/replay/source
 *         Admin-only.  Register (or update) the recorded-video metadata
 *         for a specific behavior alert.
 *
 *   GET   /api/monitoring/surveillance/alerts/{alertId}/replay
 *         Available to admin (any alert) and to non-admin users (their own
 *         alerts only).  Generates the clip on demand (or returns cached)
 *         and streams it as video/mp4.
 */
class ReplayController extends Controller
{
    // ── POST .../alerts/{alertId}/replay/source ───────────────────────────────

    /**
     * Register or update the replay source for a behavior alert.
     *
     * Body (JSON):
     *   source_video_path  string   required  Absolute path to the recorded source video.
     *   video_start_time   string   required  ISO-8601 datetime — real-world time of frame 0.
     *   event_time         string   optional  ISO-8601 datetime — defaults to alert's fired_at.
     *   pre_buffer_sec     int      optional  Seconds before event. Defaults to config.
     *   post_buffer_sec    int      optional  Seconds after event.  Defaults to config.
     *
     * Response 201 / 200 — the saved AlertReplaySource as JSON.
     * Response 404      — alert not found.
     * Response 422      — validation failure.
     */
    public function registerSource(Request $request, int $alertId): JsonResponse
    {
        $alert = BehaviorAlert::find($alertId);

        if (!$alert) {
            return response()->json(['message' => 'Alert not found.'], 404);
        }

        $data = $request->validate([
            'source_video_path' => ['required', 'string', 'max:1024'],
            'video_start_time'  => ['required', 'date'],
            'event_time'        => ['nullable', 'date'],
            'pre_buffer_sec'    => ['nullable', 'integer', 'min:0', 'max:3600'],
            'post_buffer_sec'   => ['nullable', 'integer', 'min:0', 'max:3600'],
        ]);

        // Reject paths that smell like traversal attempts.
        if (str_contains($data['source_video_path'], '..')) {
            return response()->json([
                'message' => 'source_video_path must not contain "..".',
                'code'    => 'invalid_path',
            ], 422);
        }

        $isNew  = !AlertReplaySource::where('behavior_alert_id', $alertId)->exists();
        $source = AlertReplaySource::updateOrCreate(
            ['behavior_alert_id' => $alertId],
            [
                'source_video_path' => $data['source_video_path'],
                'video_start_time'  => $data['video_start_time'],
                'event_time'        => $data['event_time'] ?? $alert->fired_at->toDateTimeString(),
                'pre_buffer_sec'    => $data['pre_buffer_sec']  ?? config('replay.pre_buffer_sec',  300),
                'post_buffer_sec'   => $data['post_buffer_sec'] ?? config('replay.post_buffer_sec',  60),
                // Invalidate any previously cached clip when source metadata changes.
                'generated_clip_path' => null,
                'clip_expires_at'     => null,
            ]
        );

        return response()->json([
            'message' => $isNew ? 'Replay source registered.' : 'Replay source updated.',
            'source'  => $this->formatSource($source),
        ], $isNew ? 201 : 200);
    }

    // ── GET .../alerts/{alertId}/replay ───────────────────────────────────────

    /**
     * Generate (or return cached) the replay clip for an alert and stream it.
     *
     * Authorization:
     *   - Admin: any alert.
     *   - Other roles: only alerts delivered to themselves (user_id = auth user).
     *
     * Response 200  — video/mp4 binary stream (inline).
     * Response 404  — alert not found, or no replay source registered.
     * Response 422  — clip could not be generated (source missing, bad window, ffmpeg error).
     */
    public function stream(Request $request, int $alertId): BinaryFileResponse|JsonResponse
    {
        $user  = $request->user();
        $alert = $this->resolveAlert($alertId, $user);

        if (!$alert) {
            return response()->json(['message' => 'Alert not found.'], 404);
        }

        $source = AlertReplaySource::where('behavior_alert_id', $alertId)->first();

        if (!$source) {
            return response()->json([
                'message' => 'No replay source registered for this alert.',
                'code'    => 'no_replay_source',
            ], 404);
        }

        try {
            $clipPath = app(ReplayService::class)->getOrGenerate($source);
        } catch (InvalidArgumentException $e) {
            // Bad timing configuration — not a clip finalization failure; skip metadata row.
            return response()->json([
                'message' => 'Replay window is invalid.',
                'reason'  => $e->getMessage(),
                'code'    => 'invalid_window',
            ], 422);
        } catch (RuntimeException $e) {
            // ffmpeg error or missing source file — record failure metadata, then surface the error.
            app(ClipMetadataService::class)->recordFailure($alert, $source, $e->getMessage());
            return response()->json([
                'message' => 'Replay clip could not be generated.',
                'reason'  => $e->getMessage(),
                'code'    => 'generation_failed',
            ], 422);
        }

        // Persist (or refresh) clip metadata — non-blocking; any failure is logged internally.
        app(ClipMetadataService::class)->ensureRecorded($alert, $source, $clipPath);

        return response()->file($clipPath, [
            'Content-Type'        => 'video/mp4',
            'Content-Disposition' => 'inline; filename="replay_alert_' . $alertId . '.mp4"',
            'X-Replay-Alert-Id'   => (string) $alertId,
            'X-Replay-Event-Time' => $source->event_time->toIso8601String(),
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolve the BehaviorAlert scoped to what the requesting user may access.
     *
     * Admin — any alert.
     * Others — only alerts that were delivered to them (user_id = auth id).
     */
    private function resolveAlert(int $alertId, \App\Models\User $user): ?BehaviorAlert
    {
        if ($user->role === 'admin') {
            return BehaviorAlert::find($alertId);
        }

        return BehaviorAlert::where('id', $alertId)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Safe public representation of an AlertReplaySource row.
     */
    private function formatSource(AlertReplaySource $source): array
    {
        return [
            'id'                  => $source->id,
            'behavior_alert_id'   => $source->behavior_alert_id,
            'source_video_path'   => $source->source_video_path,
            'video_start_time'    => $source->video_start_time->toIso8601String(),
            'event_time'          => $source->event_time->toIso8601String(),
            'pre_buffer_sec'      => $source->pre_buffer_sec,
            'post_buffer_sec'     => $source->post_buffer_sec,
            'clip_ready'          => $source->generated_clip_path !== null
                                     && $source->clip_expires_at?->isFuture(),
            'clip_expires_at'     => $source->clip_expires_at?->toIso8601String(),
        ];
    }
}
