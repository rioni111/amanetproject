<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Development / demo seed. Safe to run repeatedly: every record is keyed on a
     * natural identifier (email, client reference, matter number) and skipped if present.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Demo seeding is disabled in production. Use `php artisan lexora:create-admin` instead.');

            return;
        }

        $this->call([
            DemoAdminSeeder::class,
            DemoWorkspaceSeeder::class,
        ]);
        if (config('lexora.demo_documents')) {
            $this->call(DemoDocumentsSeeder::class);
        }
    }
}
