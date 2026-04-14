<?php

namespace App\Http\Controllers\Face;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proxy a single webcam frame to the Python embedding service for lightweight
 * face + pose validation.  No embedding is generated; the Python endpoint only
 * runs the face detector.
 *
 * POST /api/face/validate-frame
 *   multipart/form-data:
 *     image         — JPEG/PNG/WebP frame (≤ 2 MB)
 *     expected_pose — forward | left | right | up | down | any
 */
class ValidateFrameController extends Controller
{
    private const VALID_POSES = ['forward', 'left', 'right', 'up', 'down', 'any'];

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'image'         => ['required', 'file', 'mimes:jpeg,png,webp', 'max:2048'],
            'expected_pose' => ['required', 'string', 'in:forward,left,right,up,down,any'],
        ]);

        $file       = $request->file('image');
        $serviceUrl = rtrim((string) config('embedding.service_url', 'http://127.0.0.1:8765'), '/');

        try {
            $response = Http::timeout(5)
                ->attach(
                    'image',
                    $file->get(),
                    'frame.jpg',
                    ['Content-Type' => $file->getMimeType() ?? 'image/jpeg'],
                )
                ->post("{$serviceUrl}/validate-frame", [
                    'expected_pose' => $request->input('expected_pose'),
                ]);

            return response()->json($response->json());

        } catch (\Throwable $e) {
            Log::warning('[ValidateFrameController] Embedding service unreachable.', [
                'error' => $e->getMessage(),
            ]);

            // Return a graceful degraded response so the UI doesn't hard-crash.
            return response()->json([
                'ready'            => false,
                'message'          => 'Validation service unavailable — capture manually',
                'face_count'       => 0,
                'predicted_pose'   => null,
                'matches_expected' => false,
                'yaw'              => 0.0,
                'pitch'            => 0.0,
                'roll'             => 0.0,
                'quality_score'    => 0.0,
                'face_size_ratio'  => 0.0,
                'sharpness'        => 0.0,
            ]);
        }
    }
}
