<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the development-only demo administrator from DEMO_ADMIN_* environment
 * variables. No password is hard-coded in source.
 */
class DemoAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $email = config('lexora.demo_admin.email');
        $password = config('lexora.demo_admin.password');

        if (blank($email) || blank($password)) {
            $this->command?->warn('DEMO_ADMIN_EMAIL / DEMO_ADMIN_PASSWORD not set — skipping demo administrator.');

            return;
        }

        $user = User::query()->firstOrNew(['email' => mb_strtolower($email)]);
        $user->forceFill([
            'name' => config('lexora.demo_admin.name'),
            'email' => $email,
            'password' => $password, // hashed by the model cast
            'role' => UserRole::Admin,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->command?->info("Demo administrator ready: {$user->email}");
    }
}
