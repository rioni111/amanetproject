<?php

namespace Tests\Feature\Database;

use App\Models\ActivityEvent;
use App\Models\Client;
use App\Models\Matter;
use App\Models\MatterNote;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_seeds_the_documented_demo_workspace(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertSame(8, Client::count());
        $this->assertSame(10, Matter::count());
        $this->assertSame(15, Task::count());
        $this->assertSame(6, MatterNote::count());
        $this->assertGreaterThan(30, ActivityEvent::count());

        $admin = User::first();
        $this->assertSame('admin@lexora.test', $admin->email);
        $this->assertTrue(Hash::check('Test-Password-123', $admin->password));
    }

    #[Test]
    public function seeding_twice_does_not_duplicate_records(): void
    {
        $this->seed(DatabaseSeeder::class);
        $events = ActivityEvent::count();

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertSame(8, Client::count());
        $this->assertSame(10, Matter::count());
        $this->assertSame(15, Task::count());
        $this->assertSame(6, MatterNote::count());
        $this->assertSame($events, ActivityEvent::count());
    }

    #[Test]
    public function demo_seeding_is_refused_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // Invoke the seeder directly (db:seed itself would ask for confirmation in production).
        $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Matter::count());
    }
}
