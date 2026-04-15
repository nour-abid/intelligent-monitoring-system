<?php
/**
 * TestEarlyLeaveProcedure.php
 *
 * DOCUMENTATION-ONLY reference for the exact steps to set up the test user
 * for early_leave alert testing.
 *
 * This file is NOT part of the production codebase.
 * It documents the EXACT commands to run via Laravel Tinker.
 *
 * ========================================================================
 * PREREQUISITE: Surveillance DB must exist and be readable
 * ========================================================================
 *
 * STEP 1: Create or update test user with surveillance_identity
 * ──────────────────────────────────────────────────────────────
 * Run these commands in Laravel Tinker (php artisan tinker):
 *
 *     use App\Models\User;
 *     $amir = User::where('email', 'amir.dammak@mq-monitoring.local')->first();
 *
 *     User::updateOrCreate(
 *         ['email' => 'test_early_real@mq-monitoring.local'],
 *         [
 *             'name'                  => 'Test Early Real',
 *             'password'              => Hash::make('Test@1234'),
 *             'role'                  => 'viewer',
 *             'supervisor_id'         => $amir->id ?? 1,
 *             'is_active'             => true,
 *             'surveillance_identity' => 'test_early_real',
 *         ]
 *     );
 *
 * STEP 2: Insert surveillance event with end time before 17:45
 * ──────────────────────────────────────────────────────────────
 * From workspace root, run:
 *
 *     python test_insert_early_leave.py test_early_real 17:30
 *
 * This inserts ONE row with:
 *   - identity_name = 'test_early_real'
 *   - Event ending at 17:30 (15 minutes before cutoff)
 *   - Today's date
 *   - activity = 'Working'
 *
 * STEP 3: Verify no later row exists for this identity today
 * ────────────────────────────────────────────────────────────
 * Run in Tinker to check:
 *
 *     DB::connection('surveillance')
 *         ->table('surveillance_events')
 *         ->where('identity_name', 'test_early_real')
 *         ->where('timestamp_start', '>=', now()->format('Y-m-d 00:00:00'))
 *         ->orderBy('timestamp_end', 'desc')
 *         ->get();
 *
 * Expected: Only one row (the one just inserted) or none if today is new.
 *
 * STEP 4: Wait until after 18:00 (workday_end)
 * ──────────────────────────────────────────────
 * Alert evaluation ONLY runs after the workday end time.
 * The scheduler will trigger it automatically (typically on */1 minute basis).
 *
 * WARNING: If you try to run the check before 18:00, it will return 0 fired alerts.
 *
 * STEP 5: Trigger alert evaluation
 * ────────────────────────────────────
 * Option A: Wait for scheduler (automatic on minute boundary)
 * Option B: Manual trigger via Tinker:
 *
 *     app(App\Services\Monitoring\AlertEvaluationService::class)->evaluate();
 *
 * STEP 6: Verify alert and cooldown
 * ──────────────────────────────────
 * Check broadcast received on admin/superviseur channels (dashboard logs).
 * Verify alert_cooldowns table in main DB (Laravel's own SQLite):
 *
 *     DB::table('alert_cooldowns')
 *         ->where('identity_name', 'test_early_real')
 *         ->where('alert_type', 'early_leave')
 *         ->first();
 *
 * Expected: One row with last_alerted_at = current timestamp.
 *
 * ========================================================================
 * CLEANUP (optional)
 * ========================================================================
 *
 * Delete test surveillance row:
 *
 *     DB::connection('surveillance')
 *         ->table('surveillance_events')
 *         ->where('identity_source', 'test_early_leave')
 *         ->delete();
 *
 * Delete test user (if desired):
 *
 *     User::where('email', 'test_early_real@mq-monitoring.local')->delete();
 *
 * Delete cooldown record:
 *
 *     DB::table('alert_cooldowns')
 *         ->where('identity_name', 'test_early_real')
 *         ->where('alert_type', 'early_leave')
 *         ->delete();
 */
