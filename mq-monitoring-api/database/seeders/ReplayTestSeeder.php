<?php

namespace Database\Seeders;

use App\Models\AlertReplaySource;
use App\Models\BehaviorAlert;
use Illuminate\Database\Seeder;

/**
 * ReplayTestSeeder
 *
 * TEST / DEVELOPMENT ONLY — do NOT run in production.
 *
 * Creates one synthetic BehaviorAlert for "bellaaj" (phone-use) and
 * registers test5.mp4 as its replay source using short buffer values
 * suitable for the 46-second test clip.
 *
 * Design:
 *   video_start_time = 2026-03-30 10:00:00
 *   event_time       = 2026-03-30 10:00:12  (12 s into the file)
 *   pre_buffer_sec   = 5
 *   post_buffer_sec  = 10
 *
 * Generated clip covers roughly seconds 7 – 22 of test5.mp4
 * (seek_offset = max(0, 12-5) = 7, duration = 5+10 = 15 s).
 *
 * The alert is assigned to user_id = 1 (admin) so the streaming
 * endpoint is accessible when authenticated as admin.
 *
 * Idempotent: re-running the seeder will only update, never duplicate.
 *
 * To run:
 *   php artisan db:seed --class=ReplayTestSeeder
 *
 * To reverse:
 *   php artisan tinker --execute="
 *       \$a = App\Models\BehaviorAlert::where('identity_name','bellaaj')->where('alert_type','phone')->where('fired_at','2026-03-30 10:00:12')->first();
 *       if (\$a) { App\Models\AlertReplaySource::where('behavior_alert_id',\$a->id)->delete(); \$a->delete(); echo 'cleaned'; }
 *   "
 */
class ReplayTestSeeder extends Seeder
{
    /** Absolute path to the test source video. Adjust if you move the file. */
    private const TEST_VIDEO_PATH = 'C:/Users/BH RENOVATIONS/intelligent-monitoring-system/surveillance/test5.mp4';

    public function run(): void
    {
        // ── 1. Create (or find) the synthetic alert ──────────────────────────
        $alert = BehaviorAlert::firstOrCreate(
            [
                'identity_name' => 'bellaaj',
                'alert_type'    => 'phone',
                'fired_at'      => '2026-03-30 10:00:12',
            ],
            [
                'user_id'           => 1,   // admin — can stream any alert
                'duration_minutes'  => 0.5,
                'threshold_minutes' => 0,
            ]
        );

        // ── 2. Register (or update) the replay source ────────────────────────
        AlertReplaySource::updateOrCreate(
            ['behavior_alert_id' => $alert->id],
            [
                'source_video_path' => self::TEST_VIDEO_PATH,
                'video_start_time'  => '2026-03-30 10:00:00',
                'event_time'        => '2026-03-30 10:00:12',
                'pre_buffer_sec'    => 5,
                'post_buffer_sec'   => 10,
                // Clear any stale cached clip so the first request regenerates it.
                'generated_clip_path' => null,
                'clip_expires_at'     => null,
            ]
        );

        $this->command->info('ReplayTestSeeder: alert id=' . $alert->id . ' → replay source registered.');
        $this->command->info('  source: ' . self::TEST_VIDEO_PATH);
        $this->command->info('  clip window: seek=7s, duration=15s (seconds 7–22 of test5.mp4).');
        $this->command->info('  Stream endpoint: GET /api/monitoring/surveillance/alerts/' . $alert->id . '/replay');
    }
}
