<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Http\Requests\Monitoring\StoreClipMetadataRequest;
use App\Models\SurveillanceClip;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * ClipIngestionController
 *
 * Receives clip metadata posted by the Python surveillance pipeline after it
 * has confirmed and saved a video clip to disk, then persists a
 * SurveillanceClip row in PostgreSQL.
 *
 * This controller has a single action:
 *
 *   POST /api/internal/clips
 *
 * The route is protected by EnsureInternalToken; no Sanctum user session is
 * required or expected here.
 *
 * Architecture contract:
 *   - No video data is ever received or stored here.
 *   - alert_id is intentionally left null: the Python pipeline has no reliable
 *     way to know which behavior_alerts row (if any) corresponds to the event
 *     it recorded.  A separate reconciliation job can back-fill alert_id later
 *     if needed (match by identity_name + event_type + time window).
 *   - A duplicate-suppression guard uses updateOrCreate keyed on
 *     (file_path) so that a retry from Python after a network blip does not
 *     produce two rows for the same physical file.
 */
class ClipIngestionController extends Controller
{
    /**
     * Store clip metadata sent by the surveillance pipeline.
     *
     * POST /api/internal/clips
     *
     * Body (JSON):
     *   camera_id       string   required
     *   identity_name   string   required
     *   event_type      string   required
     *   started_at      ISO8601  required
     *   ended_at        ISO8601  required
     *   duration_sec    numeric  required
     *   file_path       string   required
     *   file_name       string   optional
     *   file_size_bytes int      optional
     *   mime_type       string   optional  default: video/mp4
     *   pre_buffer_sec  int      optional
     *   post_buffer_sec int      optional
     *   clip_status     string   optional  default: ready
     *
     * Response 201 — the created/updated SurveillanceClip resource.
     * Response 422 — validation failure.
     * Response 500 — unexpected persistence failure.
     */
    public function store(StoreClipMetadataRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $clip = SurveillanceClip::updateOrCreate(
                // Unique key: one row per physical file, regardless of how many
                // times Python retries the POST.
                ['file_path' => $data['file_path']],
                [
                    'alert_id'        => null,   // reconciled later if needed
                    'camera_id'       => $data['camera_id'],
                    'identity_name'   => $data['identity_name'],
                    'event_type'      => $data['event_type'],
                    'started_at'      => $data['started_at'],
                    'ended_at'        => $data['ended_at'],
                    'duration_sec'    => (int) round($data['duration_sec']),
                    'file_name'       => $data['file_name'] ?? basename($data['file_path']),
                    'file_size_bytes' => $data['file_size_bytes'] ?? null,
                    'mime_type'       => $data['mime_type'] ?? 'video/mp4',
                    'pre_buffer_sec'  => $data['pre_buffer_sec'] ?? null,
                    'post_buffer_sec' => $data['post_buffer_sec'] ?? null,
                    'clip_status'     => $data['clip_status'] ?? 'ready',
                ]
            );

            Log::info('[clip_ingestion] Clip metadata persisted', [
                'clip_id'       => $clip->id,
                'identity_name' => $clip->identity_name,
                'event_type'    => $clip->event_type,
                'file_path'     => $clip->file_path,
                'was_updated'   => !$clip->wasRecentlyCreated,
            ]);

            return response()->json($clip->toArray(), $clip->wasRecentlyCreated ? 201 : 200);
        } catch (\Throwable $e) {
            Log::error('[clip_ingestion] Failed to persist clip metadata', [
                'error'   => $e->getMessage(),
                'payload' => $data,
            ]);

            return response()->json(['message' => 'Failed to store clip metadata.'], 500);
        }
    }
}
