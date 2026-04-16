<?php

namespace App\Console\Commands;

use App\Models\AlertCooldown;
use App\Services\Monitoring\AlertEvaluationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Artisan command: php artisan alerts:demo {identity}
 *
 * Seeds fake surveillance / attendance data for the given identity and
 * immediately runs alert evaluation so all three alert types can be
 * demonstrated without waiting for real activity to accumulate.
 *
 * Alert types seeded:
 *   phone       — 35 seconds of Using_Phone starting 40 seconds ago
 *   inactive    — 35 seconds of Inactive     starting 40 seconds ago
 *   late_arrival — attendance check_in recorded 45 minutes after the
 *                  configured workday_start + tolerance cutoff
 *   early_leave  — last surveillance presence set to 2 hours before
 *                  workday_end - tolerance, with force mode enabled
 *
 * Usage:
 *   php artisan alerts:demo Nour
 *   php artisan alerts:demo Nour --type=phone
 *   php artisan alerts:demo Nour --type=late_arrival
 *   php artisan alerts:demo Nour --type=early_leave
 *   php artisan alerts:demo Nour --type=all   (default)
 *
 * Flags:
 *   --type=     One of: phone | inactive | late_arrival | early_leave | all
 *   --no-clean  Skip deleting the seeded rows after evaluation
 */
class DemoAlertsCommand extends Command
{
    protected $signature = 'alerts:demo
        {identity        : Surveillance identity name (must match user.surveillance_identity)}
        {--type=all      : Alert type to seed: phone|inactive|late_arrival|early_leave|all}
        {--no-clean      : Keep seeded rows in the database after the demo}';

    protected $description = 'Seed fake activity data and fire demo alerts for a given identity';

    private const SURV_CONN = 'surveillance';
    private const SURV_TABLE = 'surveillance_events';
    private const ATT_CONN  = 'attendance';
    private const ATT_TABLE = 'attendance_events';

    /** Tracks inserted row IDs so we can clean up afterwards. */
    private array $insertedSurvIds = [];
    private array $insertedAttIds  = [];

    public function handle(AlertEvaluationService $service): int
    {
        $identity = (string) $this->argument('identity');
        $type     = (string) $this->option('type');
        $clean    = !$this->option('no-clean');

        $this->info("=== Marqi Alert Demo ===");
        $this->info("Identity : {$identity}");
        $this->info("Type     : {$type}");
        $this->line('');

        // Clear existing cooldowns so previous runs don't block new alerts.
        $cleared = AlertCooldown::where('identity_name', $identity)->delete();
        if ($cleared) {
            $this->line("  [✓] Cleared {$cleared} cooldown record(s) for {$identity}");
        }

        $seeded = false;

        // ── Phone alert ───────────────────────────────────────────────────────
        if (in_array($type, ['phone', 'all'], true)) {
            $this->seedSurveillance($identity, 'Using_Phone', 35);
            $this->line("  [✓] Seeded 35s of Using_Phone activity");
            $seeded = true;
        }

        // ── Inactive alert ────────────────────────────────────────────────────
        if (in_array($type, ['inactive', 'all'], true)) {
            $this->seedSurveillance($identity, 'Inactive', 35);
            $this->line("  [✓] Seeded 35s of Inactive activity");
            $seeded = true;
        }

        // ── Late arrival alert ────────────────────────────────────────────────
        if (in_array($type, ['late_arrival', 'all'], true)) {
            $this->seedLateArrival($identity);
            $this->line("  [✓] Seeded late check_in for {$identity}");
            $seeded = true;
        }

        // ── Early leave alert (requires --force on checkEarlyLeave) ───────────
        if (in_array($type, ['early_leave', 'all'], true)) {
            $this->seedEarlyLeave($identity);
            $this->line("  [✓] Seeded early-leave last-seen for {$identity}");
            $seeded = true;
        }

        if (!$seeded) {
            $this->error("Unknown --type value: {$type}. Use: phone|inactive|late_arrival|early_leave|all");
            return Command::FAILURE;
        }

        $this->line('');
        $this->info("Running alert evaluation...");

        // Force-mode passes true to checkEarlyLeave so the workday_end time guard is bypassed.
        $forceEarlyLeave = in_array($type, ['early_leave', 'all'], true);
        $count = $service->evaluate($forceEarlyLeave);

        $this->info("  Fired: {$count} alert(s)");

        if ($count === 0) {
            $this->warn("  No alerts fired. Check that:");
            $this->warn("    1. BROADCAST_CONNECTION=reverb in .env");
            $this->warn("    2. Reverb is running: php artisan reverb:start");
            $this->warn("    3. The identity '{$identity}' matches a user's surveillance_identity");
            $this->warn("    4. That user is assigned an admin or superviseur recipient");
        }

        if ($clean) {
            $this->cleanUp();
            $this->line("  [✓] Cleaned up seeded rows");
        }

        $this->line('');
        $this->info("Done. Watch the dashboard for incoming alert toasts.");

        return Command::SUCCESS;
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Insert a fake surveillance event starting 20 seconds ago so it falls
     * inside the 30-second rolling window used by checkActivity().
     * (The window query is timestamp_start >= now()-thresholdSec, so the event
     * must have started within the threshold window, not 40s ago.)
     */
    private function seedSurveillance(string $identity, string $activity, int $durationSec): void
    {
        $start = now()->subSeconds(20);
        $end   = $start->copy()->addSeconds($durationSec);

        $id = DB::connection(self::SURV_CONN)
            ->table(self::SURV_TABLE)
            ->insertGetId([
                'identity_name'       => $identity,
                'activity'            => $activity,
                'timestamp_start'     => $start->format('Y-m-d H:i:s'),
                'timestamp_end'       => $end->format('Y-m-d H:i:s'),
                'duration_sec'        => $durationSec,
                'track_id'            => 0,
                'identity_confidence' => 0.99,
                'identity_source'     => 'demo',
                'event_trigger'       => 'demo',
            ]);

        $this->insertedSurvIds[] = $id;
    }

    /**
     * Ensure the attendance schema and table exist (creates them if missing).
     * This is safe to call multiple times — uses IF NOT EXISTS.
     */
    private function ensureAttendanceTable(): void
    {
        $conn = DB::connection(self::ATT_CONN);
        $conn->statement('CREATE SCHEMA IF NOT EXISTS attendance');
        $conn->statement("
            CREATE TABLE IF NOT EXISTS attendance.attendance_events (
                id     BIGSERIAL PRIMARY KEY,
                ts     DOUBLE PRECISION NOT NULL,
                ts_at  TIMESTAMPTZ,
                person TEXT,
                event  TEXT
            )
        ");
    }

    /**
     * Insert a fake check_in for today that is 45 minutes past the configured
     * late arrival cutoff (workday_start + tolerance).
     */
    private function seedLateArrival(string $identity): void
    {
        $this->ensureAttendanceTable();

        $start       = config('alerts.late_workday_start', '08:00');
        $tolerancMin = (int) config('alerts.late_tolerance_minutes', 15);

        // Place check_in 45 minutes after the cutoff
        $cutoffTs   = strtotime(now()->format('Y-m-d') . ' ' . $start) + $tolerancMin * 60;
        $checkinTs  = $cutoffTs + 45 * 60;
        $checkinStr = date('Y-m-d H:i:sP', $checkinTs); // ISO 8601 with timezone offset

        $id = DB::connection(self::ATT_CONN)
            ->table(self::ATT_TABLE)
            ->insertGetId([
                'ts'     => (float) $checkinTs,
                'ts_at'  => $checkinStr,
                'person' => $identity,
                'event'  => 'check_in',
            ]);

        $this->insertedAttIds[] = $id;
    }

    /**
     * Insert a fake surveillance last-seen event that is 2 hours before
     * (workday_end - tolerance), so checkEarlyLeave fires when force=true.
     */
    private function seedEarlyLeave(string $identity): void
    {
        $end          = config('alerts.early_leave_workday_end', '18:00');
        $tolerancMin  = (int) config('alerts.early_leave_tolerance_minutes', 15);

        $workdayEndTs = strtotime(now()->format('Y-m-d') . ' ' . $end);
        $cutoffTs     = $workdayEndTs - $tolerancMin * 60;
        // Last seen 2 hours before the cutoff — clearly an early leave
        $lastSeenTs   = $cutoffTs - 2 * 3600;
        $lastSeenStr  = date('Y-m-d H:i:s', $lastSeenTs);

        // Insert a 30-second Working segment so last_seen_end = lastSeenStr + 30s
        $endStr = date('Y-m-d H:i:s', $lastSeenTs + 30);

        $id = DB::connection(self::SURV_CONN)
            ->table(self::SURV_TABLE)
            ->insertGetId([
                'identity_name'       => $identity,
                'activity'            => 'Working',
                'timestamp_start'     => $lastSeenStr,
                'timestamp_end'       => $endStr,
                'duration_sec'        => 30,
                'track_id'            => 0,
                'identity_confidence' => 0.99,
                'identity_source'     => 'demo',
                'event_trigger'       => 'demo',
            ]);

        $this->insertedSurvIds[] = $id;
    }

    private function cleanUp(): void
    {
        if (!empty($this->insertedSurvIds)) {
            DB::connection(self::SURV_CONN)
                ->table(self::SURV_TABLE)
                ->whereIn('id', $this->insertedSurvIds)
                ->delete();
        }

        if (!empty($this->insertedAttIds)) {
            DB::connection(self::ATT_CONN)
                ->table(self::ATT_TABLE)
                ->whereIn('id', $this->insertedAttIds)
                ->delete();
        }
    }
}
