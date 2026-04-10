<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * UsersSeeder — realistic test users for every supported role.
 *
 * Credentials (change before any non-local use):
 *   All test accounts: Test@1234
 *
 * surveillance_identity values must match EXACTLY the identity_name strings
 * used in seed_surveillance.py and written by the Python runtime.
 *
 * Role / supervisor mapping:
 *   admin       — Nour Abid
 *   superviseur — Amir Dammak         (no supervisor)
 *   superviseur — Mohamed Y. Bellaaj  (no supervisor)
 *   viewer      — Yessine Gargouri    → Amir Dammak
 *   viewer      — Mouhamed A. Elleuch → Amir Dammak
 *   viewer      — Rahma Boukhris      → Mohamed Y. Bellaaj
 *
 * Safe to re-run: uses updateOrCreate keyed on email.
 * The generic `admin@mq-monitoring.local` row from AdminSeeder is untouched.
 */
class UsersSeeder extends Seeder
{
    public function run(): void
    {
        // ── 0. Pre-flight: release conflicting surveillance_identity values ────
        // If a manually-created account already owns one of the seed identity
        // tokens, NULL it out so the updateOrCreate calls below can claim it.
        // Only rows whose email is NOT a seed email are touched.
        $seedEmails = [
            'nour.abid@mq-monitoring.local',
            'amir.dammak@mq-monitoring.local',
            'bellaaj@mq-monitoring.local',
            'yessine.gargouri@mq-monitoring.local',
            'amine.elleuch@mq-monitoring.local',
            'rahma.boukhris@mq-monitoring.local',
        ];

        User::whereIn('surveillance_identity', [
            'nour.abid', 'amir.dammak', 'bellaaj',
            'yessine.gargouri', 'amine.elleuch', 'rahma.boukhris',
        ])
            ->whereNotIn('email', $seedEmails)
            ->update(['surveillance_identity' => null]);

        // ── 1. Admin ──────────────────────────────────────────────────────────
        User::updateOrCreate(
            ['email' => 'nour.abid@mq-monitoring.local'],
            [
                'name'                  => 'Nour Abid',
                'password'              => Hash::make('Test@1234'),
                'role'                  => 'admin',
                'supervisor_id'         => null,
                'is_active'             => true,
                'surveillance_identity' => 'nour.abid',
            ]
        );

        // ── 2. Superviseurs ───────────────────────────────────────────────────
        $amir = User::updateOrCreate(
            ['email' => 'amir.dammak@mq-monitoring.local'],
            [
                'name'                  => 'Amir Dammak',
                'password'              => Hash::make('Test@1234'),
                'role'                  => 'superviseur',
                'supervisor_id'         => null,
                'is_active'             => true,
                'surveillance_identity' => 'amir.dammak',
            ]
        );

        $bellaaj = User::updateOrCreate(
            ['email' => 'bellaaj@mq-monitoring.local'],
            [
                'name'                  => 'Mohamed Yessine Bellaaj',
                'password'              => Hash::make('Test@1234'),
                'role'                  => 'superviseur',
                'supervisor_id'         => null,
                'is_active'             => true,
                'surveillance_identity' => 'bellaaj',
            ]
        );

        // ── 3. Viewers / Employees ────────────────────────────────────────────
        User::updateOrCreate(
            ['email' => 'yessine.gargouri@mq-monitoring.local'],
            [
                'name'                  => 'Yessine Gargouri',
                'password'              => Hash::make('Test@1234'),
                'role'                  => 'viewer',
                'supervisor_id'         => $amir->id,
                'is_active'             => true,
                'surveillance_identity' => 'yessine.gargouri',
            ]
        );

        User::updateOrCreate(
            ['email' => 'amine.elleuch@mq-monitoring.local'],
            [
                'name'                  => 'Mouhamed Amine Elleuch',
                'password'              => Hash::make('Test@1234'),
                'role'                  => 'viewer',
                'supervisor_id'         => $amir->id,
                'is_active'             => true,
                'surveillance_identity' => 'amine.elleuch',
            ]
        );

        User::updateOrCreate(
            ['email' => 'rahma.boukhris@mq-monitoring.local'],
            [
                'name'                  => 'Rahma Boukhris',
                'password'              => Hash::make('Test@1234'),
                'role'                  => 'viewer',
                'supervisor_id'         => $bellaaj->id,
                'is_active'             => true,
                'surveillance_identity' => 'rahma.boukhris',
            ]
        );
    }
}
