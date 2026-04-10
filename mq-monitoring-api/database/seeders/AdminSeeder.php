<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Create the first admin account.
     *
     * Credentials (change immediately after first login):
     *   email:    admin@mq-monitoring.local
     *   password: admin1234
     *
     * The `name` field must match the identity_name stored in
     * surveillance_events for supervisor-scoping to resolve correctly.
     * Admins are not subject to identity scoping, so any name is fine here.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@mq-monitoring.local'],
            [
                'name'          => 'admin',
                'password'      => Hash::make('admin1234'),
                'role'          => 'admin',
                'supervisor_id' => null,
                'is_active'     => true,
            ]
        );
    }
}
